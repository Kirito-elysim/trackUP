import * as React from 'react';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { cn } from '@/lib/utils';

// eslint-disable-next-line react-refresh/only-export-components -- re-exported Radix primitive, not a local component
export const TooltipProvider = TooltipPrimitive.Provider;
// eslint-disable-next-line react-refresh/only-export-components -- re-exported Radix primitive, not a local component
export const Tooltip = TooltipPrimitive.Root;
// eslint-disable-next-line react-refresh/only-export-components -- re-exported Radix primitive, not a local component
export const TooltipTrigger = TooltipPrimitive.Trigger;

export function TooltipContent({
  className,
  sideOffset = 6,
  ...props
}: React.ComponentPropsWithoutRef<typeof TooltipPrimitive.Content>) {
  return (
    <TooltipPrimitive.Portal>
      <TooltipPrimitive.Content
        sideOffset={sideOffset}
        className={cn(
          'animate-overlay-in z-50 max-w-64 rounded-lg border border-border bg-card px-3 py-2 text-xs text-card-foreground shadow-soft-hover',
          className,
        )}
        {...props}
      />
    </TooltipPrimitive.Portal>
  );
}
