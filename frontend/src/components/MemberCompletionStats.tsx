import { Users, Video, Laptop } from 'lucide-react';
import { formatPercentage } from '../lib/format';
import { OverviewStat } from './OverviewStat';

// Rangée de stats partagée entre la fiche groupe et la fiche parcours (roadmap : les deux vues
// doivent évoluer ensemble) : effectif + complétion masterclass/e-learning moyennes, calculées côté
// backend à partir des mêmes ratios temps réel/temps prévu déjà affichés par apprenant dans le tableau.
export function MemberCompletionStats({
  memberLabel,
  memberCount,
  averageMasterclassCompletion,
  averageElearningCompletion,
}: {
  memberLabel: string;
  memberCount: number;
  averageMasterclassCompletion: number;
  averageElearningCompletion: number;
}) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
      <OverviewStat icon={Users} label={memberLabel} value={memberCount} delay={0} />
      <OverviewStat icon={Video} label="Completion masterclass moyenne" value={formatPercentage(averageMasterclassCompletion)} delay={80} />
      <OverviewStat icon={Laptop} label="Completion e-learning moyenne" value={formatPercentage(averageElearningCompletion)} delay={160} />
    </div>
  );
}
