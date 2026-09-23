import { useEffect, useRef, type ReactNode } from 'react';

export type WorkspaceNavItem = Readonly<{
  id: string;
  label: string;
  number: string;
  href?: string;
  stage?: string;
}>;

type WorkspaceNavigationProps = Readonly<{
  mode: 'preview' | 'admin';
  items: ReadonlyArray<WorkspaceNavItem>;
  active: string;
  footer: ReactNode;
  onSelect?: (id: string) => void;
}>;

// Both workspaces share one navigational structure. Preview items are
// interactive concepts; private workspace items are real page/section links.
export function WorkspaceNavigation({
  mode, items, active, footer, onSelect,
}: WorkspaceNavigationProps) {
  const navigationRef = useRef<HTMLElement | null>(null);

  useEffect(() => {
    const nav = navigationRef.current;
    if (!nav) return;

    // Mobile menus are horizontal. Reposition only the nav's own scroll area,
    // never the document: an anchor selection must not jump away from content.
    const revealActive = () => {
      if (nav.scrollWidth <= nav.clientWidth + 1) return;
      const selected = nav.querySelector<HTMLElement>('.workspace-nav-item.active, .workspace-nav-item.selected');
      if (!selected) return;
      const viewport = nav.getBoundingClientRect();
      const item = selected.getBoundingClientRect();
      const gutter = 8;
      if (item.left < viewport.left + gutter) {
        nav.scrollLeft += item.left - viewport.left - gutter;
      } else if (item.right > viewport.right - gutter) {
        nav.scrollLeft += item.right - viewport.right + gutter;
      }
    };
    revealActive();
    window.addEventListener('resize', revealActive);
    return () => window.removeEventListener('resize', revealActive);
  }, [active, mode]);

  return (
    <aside className={'workspace-navigation ' + (mode === 'admin' ? 'admin-sidebar' : 'preview-aside')}>
      <a className="workspace-brand" href="/" aria-label="GrindFlow, inicio">
        GRIND<span>FLOW</span><span className="brand-dot" aria-hidden="true">●</span>
      </a>
      <span className="workspace-navigation-label">
        {mode === 'admin' ? 'ESPACIO PRIVADO' : 'VISTA PREVIA'}
      </span>
      <span className="workspace-navigation-scroll-hint">Desliza para explorar más secciones ↔</span>
      <nav ref={navigationRef} aria-label={mode === 'admin' ? 'Navegación administrativa' : 'Explorador de secciones'}>
        {items.map((item) => {
          const selected = active === item.id;
          const contents = (
            <>
              <span className="workspace-nav-number" aria-hidden="true">{item.number}</span>
              <span className="workspace-nav-title">{item.label}</span>
              {item.stage && <small className="workspace-nav-stage">{item.stage}</small>}
            </>
          );
          return mode === 'preview' ? (
            <button key={item.id} type="button"
              className={'workspace-nav-item preview-tab' + (selected ? ' selected' : '')}
              aria-pressed={selected} onClick={() => onSelect?.(item.id)}>
              {contents}
            </button>
          ) : (
            <a key={item.id} href={item.href ?? '#contenido'}
              className={'workspace-nav-item' + (selected ? ' active' : '')}
              aria-current={selected ? (item.href?.startsWith('#') ? 'location' : 'page') : undefined}>
              {contents}
            </a>
          );
        })}
      </nav>
      <div className="workspace-navigation-footer">{footer}</div>
    </aside>
  );
}
