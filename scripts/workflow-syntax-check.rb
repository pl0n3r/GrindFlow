#!/usr/bin/env ruby
# Prevent an invalid Actions YAML file or broken embedded smoke shell from reaching main.
# Uses only Ruby standard library available on ubuntu-latest; never runs workflow commands.
require "yaml"
require "open3"

root = File.expand_path("..", __dir__)
paths = Dir.glob(File.join(root, ".github/workflows/*.{yml,yaml}")).sort
abort "No GitHub Actions workflows found" if paths.empty?

paths.each do |path|
  begin
    YAML.parse_file(path)
  rescue Psych::SyntaxError => error
    warn "Invalid workflow YAML #{File.basename(path)}: #{error.message}"
    exit 1
  end
  puts "PASS YAML #{File.basename(path)}"
end

# GitHub removed the Node 20 JavaScript-action runtime. Keep the official
# actions that previously emitted runtime warnings on Node 24-capable majors.
obsolete_node20_actions = {
  "actions/checkout@v4" => "actions/checkout@v5+",
  "actions/cache@v4" => "actions/cache@v5+",
  "actions/setup-node@v4" => "actions/setup-node@v5+",
  "actions/upload-artifact@v4" => "actions/upload-artifact@v6+",
  "actions/checkout@11d5960a326750d5838078e36cf38b85af677262" => "actions/checkout@v5+",
  "actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02" => "actions/upload-artifact@v6+",
}.freeze

paths.each do |path|
  source = File.read(path, encoding: "UTF-8")
  obsolete_node20_actions.each do |reference, replacement|
    next unless source.include?(reference)

    abort "#{File.basename(path)} uses obsolete Node 20 action #{reference}; use #{replacement}"
  end
end
puts "PASS official GitHub Actions use Node 24-capable majors"

# Checkout is only used to read repository content in GrindFlow workflows.
# Do not leave the injected GitHub token in .git/config for later processes.
paths.each do |path|
  workflow = YAML.safe_load_file(path, aliases: true)
  jobs = workflow.fetch("jobs", {})
  next unless jobs.is_a?(Hash)

  jobs.each_value do |job|
    next unless job.is_a?(Hash)

    Array(job["steps"]).each do |step|
      next unless step.is_a?(Hash)
      next unless step["uses"].is_a?(String) && step["uses"].downcase.start_with?("actions/checkout@")

      options = step.fetch("with", {})
      unless options.is_a?(Hash) && options["persist-credentials"] == false
        abort "#{File.basename(path)} checkout must set persist-credentials: false"
      end
    end
  end
end
puts "PASS checkout credentials are not persisted"

smoke_path = File.join(root, ".github/workflows/production-smoke.yml")
smoke = YAML.safe_load_file(smoke_path, aliases: true)
steps = smoke.fetch("jobs").fetch("production-smoke").fetch("steps")
checked = 0

steps.each do |step|
  next unless step.is_a?(Hash) && step["run"].is_a?(String)

  script = step.fetch("run")
  _stdout, stderr, status = Open3.capture3("bash", "-n", stdin_data: script)
  unless status.success?
    warn "Invalid shell in production-smoke.yml step #{step.fetch("name", "unnamed")}: #{stderr}"
    exit 1
  end
  checked += 1
end

# Production Smoke provisions its dedicated synthetic identity through verified
# GitHub OIDC. Keep the credential run-scoped: generate it with a CSPRNG, mask
# it before exporting to GITHUB_ENV, and never depend on a persistent repo secret.
credentials_step = steps.find { |step| step["name"] == "Generate ephemeral synthetic credential" }
abort "Production smoke must generate an ephemeral synthetic credential" if credentials_step.nil?
credential_script = credentials_step.fetch("run")
abort "Production smoke must use openssl CSPRNG for the ephemeral credential" unless credential_script.include?('password="$(openssl rand -hex 32)"')
abort "Production smoke must validate the generated credential shape" unless credential_script.include?('[[ "$password" =~ ^[0-9a-f]{64}$ ]] || {')
mask_line = credential_script.index('echo "::add-mask::$password"')
env_line = credential_script.index('echo "E2E_USER_PASSWORD=$password" >> "$GITHUB_ENV"')
abort "Production smoke must mask the ephemeral credential before exporting it" if mask_line.nil? || env_line.nil? || mask_line >= env_line

smoke_source = File.read(smoke_path, encoding: "UTF-8")
abort "Production smoke must not depend on persistent PRODUCTION_E2E_PASSWORD secret" if smoke_source.include?("secrets.PRODUCTION_E2E_PASSWORD")
abort "Production smoke must not retain the obsolete unconfigured-secret failure" if steps.any? { |step| step["name"] == "Fail unconfigured authenticated production smoke" }

abort "Production smoke must not retain legacy configured guards" if smoke_source.include?("steps.credentials.outputs.configured")
abort "Production smoke must not export credential state through GITHUB_OUTPUT" if credential_script.include?("GITHUB_OUTPUT")
abort "Production smoke must not retain obsolete config-issue routing" if smoke_source.include?("CONFIG_ISSUE_TITLE")

recovery = steps.find { |step| step["name"] == "Publish production recovery" }
abort "Production smoke recovery step missing" if recovery.nil?
abort "Ephemeral-credential blocker may close only after successful smoke" unless recovery["if"] == "steps.smoke.outcome == 'success'"
recovery_script = recovery.fetch("run")
abort "Production smoke recovery must close issue 369 explicitly" unless smoke_source.include?("EPHEMERAL_CREDENTIAL_ISSUE: '369'") && recovery_script.include?('gh issue close "$EPHEMERAL_CREDENTIAL_ISSUE"')
abort "No smoke shell steps checked" if checked.zero?
puts "PASS production-smoke embedded Bash syntax (#{checked} steps)"

# El observador de deploy usa /health como señal canónica read-only y exige
# versión + SHA exactos. Production Smoke conserva la validación funcional.
observer_path = File.join(root, ".github/workflows/production-deploy-observer.yml")
observer = YAML.safe_load_file(observer_path, aliases: true)
abort "Observer must retain dynamic run identity" unless observer.fetch("run-name").include?("${{ github.run_number }}")
observer_job = observer.fetch("jobs").fetch("observe")
abort "Observer must run only on main" unless observer_job.fetch("if").include?("refs/heads/main")
observer_steps = observer_job.fetch("steps")
observer_steps.each do |step|
  next unless step.is_a?(Hash) && step["run"].is_a?(String)

  _out, err, code = Open3.capture3("bash", "-n", stdin_data: step["run"])
  abort "Invalid observer shell: #{err}" unless code.success?
end
probe = observer_steps.find { |step| step["name"] == "Observe exact deployed checkout without production writes" }
abort "Observer GET probe missing" if probe.nil?
script = probe.fetch("run")
abort "Observer must probe /health" unless script.include?("/health?probe=")
abort "Observer must fail on missing exact checkout" unless script.include?('[[ "$observed" == true ]] || exit 1')
abort "Observer must use exactly one curl request" unless script.scan(/\bcurl\b/).length == 1
# Bloquear opciones de escritura cortas con valor separado o pegado (-XPOST, -dJSON, etc.).
curl_write_option = /--(?:data(?:-[a-z-]+)?|request|upload-file|form(?:-string)?|json)\b|(?:^|\s)-(?:X|d|F|T)(?:\S*)/m
%w[-XPOST -dpayload -Ffield=value -Tarchivo -X -d -F -T --data-binary].each do |option|
  abort "Observer write-option guard missing #{option}" unless curl_write_option.match?("curl #{option}")
end
abort "Observer must be read-only" if script.match?(curl_write_option)
abort "Observer must never use the legacy deployment marker" if script.include?("/_deployment")
abort "Observer must require exact SHA health identity" unless script.include?('.exact == true and .commit == $sha')
puts "PASS exact-health deploy observer shell and safety contract"
