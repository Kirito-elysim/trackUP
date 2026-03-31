type PlaceholderPageProps = {
  title: string;
  eyebrow: string;
  description: string;
};

export function PlaceholderPage({ title, eyebrow, description }: PlaceholderPageProps) {
  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">{eyebrow}</p>
          <h2>{title}</h2>
        </div>
      </div>

      <div className="hero-panel hero-panel-compact">
        <div className="hero-copy">
          <span className="status-chip">Module préparé</span>
          <h3>{title} arrive ensuite.</h3>
          <p>{description}</p>
        </div>
      </div>
    </section>
  );
}
