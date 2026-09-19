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

# A missing production test secret is not a passing production smoke.
# Keep incident reporting before the final failure step and check this contract in CI.
missing_notice = steps.find_index { |step| step["name"] == "Report missing smoke credentials" }
missing_failure = steps.find_index { |step| step["name"] == "Fail unconfigured authenticated production smoke" }
abort "Production smoke must report missing credentials before failing" if missing_notice.nil? || missing_failure.nil? || missing_notice >= missing_failure
abort "Unconfigured smoke must be the final step" unless missing_failure == steps.length - 1
guard = steps.fetch(missing_failure)
abort "Unconfigured smoke is missing its exact credentials guard" unless guard["if"] == "steps.credentials.outputs.configured == 'false'"
abort "Unconfigured smoke must exit nonzero" unless guard["run"].match?(/(?:^|\n)\s*exit [1-9][0-9]*\s*(?:\n|\z)/)

abort "No smoke shell steps checked" if checked.zero?
puts "PASS production-smoke embedded Bash syntax (#{checked} steps)"

# El observador de release es otro workflow: GET público sin secretos ni
# mutaciones, con salida no exitosa cuando la versión no se observa.
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
probe = observer_steps.find { |step| step["name"] == "Observe release marker without production writes" }
abort "Observer GET probe missing" if probe.nil?
script = probe.fetch("run")
abort "Observer must probe only the public version marker" unless script.include?("/_deployment?probe=")
abort "Observer must never claim the remote SHA" unless script.include?("Hostinger checkout SHA: **NOT OBSERVED**")
abort "Observer must fail on missing release" unless script.include?('[[ "$observed" == true ]] || exit 1')
abort "Observer must be read-only" if script.match?(/curl[^\n]*(?:--data|--request| -X | -d )/)
puts "PASS release-only deploy observer shell and safety contract"
