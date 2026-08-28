import type { Users } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { CountUp } from '@/components/ui/stat';

// Carte de stat générique utilisée sur les fiches groupe/parcours (partagée pour que la mise en page
// reste identique entre les deux — toute modification ici s'applique aux deux pages).
export function OverviewStat({
  icon: Icon,
  label,
  value,
  delay,
}: {
  icon: typeof Users;
  label: string;
  value: string | number;
  delay: number;
}) {
  return (
    <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover" style={{ animationDelay: `${delay}ms` }}>
      <CardContent className="flex items-center gap-4 p-5">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-brand text-white">
          <Icon size={20} />
        </span>
        <div>
          <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p>
          <CountUp value={value} className="text-xl" />
        </div>
      </CardContent>
    </Card>
  );
}
