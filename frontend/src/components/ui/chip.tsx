import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

// A soft-tint status pill — the friendlier sibling of Badge, used for table
// status columns and headline tags where a little more presence helps.
const chipVariants = cva(
  'inline-flex w-fit items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold',
  {
    variants: {
      variant: {
        primary: 'bg-primary/10 text-primary',
        accent: 'bg-accent/10 text-accent',
        success: 'bg-success/10 text-success',
        destructive: 'bg-destructive/10 text-destructive',
        neutral: 'bg-muted text-muted-foreground',
        gradient: 'bg-gradient-brand text-white',
        onGradient: 'bg-white/20 text-white backdrop-blur-sm',
      },
    },
    defaultVariants: {
      variant: 'neutral',
    },
  },
);

export interface ChipProps extends React.HTMLAttributes<HTMLSpanElement>, VariantProps<typeof chipVariants> {}

export function Chip({ className, variant, ...props }: ChipProps) {
  return <span className={cn(chipVariants({ variant, className }))} {...props} />;
}
