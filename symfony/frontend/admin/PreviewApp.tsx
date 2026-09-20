import { useState } from 'react';

type PreviewAppProps = Readonly<{ version: string }>;
type Stage = 'biblioteca' | 'reglas' | 'trafico';
const sections: ReadonlyArray<{ id: Stage; label: string; number: string; description: string }> = [
  { id: 'biblioteca', label: 'Biblioteca', number: '01', description: 'Un lugar para reunir tus recursos y tener claro cómo utilizarlos.' },
  { id: 'reglas', label: 'Programación', number: '02', description: 'Tú defines el ritmo, los usos y los destinos autorizados.' },
  { id: 'trafico', label: 'Tráfico', number: '03', description: 'Entiende qué está funcionando con resultados medibles.' }
];

export function PreviewApp({ version }: PreviewAppProps) {
  const [active, setActive] = useState<Stage>('biblioteca');
  const chosen = sections.find((section) => section.id === active) ?? sections[0];

  return (
    <div className="preview-shell">
      <aside className="preview-aside">
        <a className="brand" href="/" aria-label="GrindFlow, inicio">GRIND<span>FLOW</span><span className="brand-dot">●</span></a>
        <span className="preview-label">ESPACIO DE TRABAJO</span>
        <nav aria-label="Explorador de secciones">
          {sections.map((section) => (
            <button
              key={section.id}
              type="button"
              aria-current={active === section.id ? 'page' : undefined}
              onClick={() => setActive(section.id)}
              className={active === section.id ? 'preview-tab selected' : 'preview-tab'}
            >
              <span>{section.number}</span>{section.label}
            </button>
          ))}
        </nav>
        <div className="preview-aside-bottom"><span className="status-dot" /> Entorno S0, sin datos reales</div>
      </aside>
      <div className="preview-workspace">
        <header className="preview-toolbar">
          <span>VISTA PREVIA <span aria-hidden="true">/</span> {chosen.label.toUpperCase()}</span>
          <span className="version-tag">V {version}</span>
        </header>
        <main className="preview-content">
          <span className="eyebrow"><span className="pulse" /> PRIMERA VISTA FUNCIONAL</span>
          <h1>Tu contenido.<br/><em>Tu ritmo.</em></h1>
          <p className="preview-lead">Explora el lenguaje visual de GrindFlow. El flujo de carga, las reglas y las integraciones se añadirán por entregables verificables.</p>
          <div className="preview-banner" role="status"><strong>Vista previa de interfaz</strong><p>Esta pantalla permite navegar entre conceptos. Todavía no gestiona archivos ni publica en redes y no solicita credenciales.</p></div>
          <section className="preview-board" aria-label={'Concepto de ' + chosen.label}>
            <div className="board-heading"><span>0{sections.findIndex((s) => s.id === active) + 1} / MÓDULO</span><span>EN PREPARACIÓN</span></div>
            <h2>{chosen.label}</h2>
            <p>{chosen.description}</p>
            <div className="board-grid" aria-hidden="true">
              <span className="board-tile one" /><span className="board-tile two" /><span className="board-tile three" />
            </div>
          </section>
          <div className="preview-bottom"><a href="/">← Volver al inicio</a><span>GrindFlow / arquitectura Symfony + React</span></div>
        </main>
      </div>
    </div>
  );
}
