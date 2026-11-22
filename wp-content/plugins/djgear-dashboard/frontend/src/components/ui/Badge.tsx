import React from 'react';

export type BadgeTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export interface BadgeProps {
  tone?: BadgeTone;
  children: React.ReactNode;
}

export function Badge({ tone = 'neutral', children }: BadgeProps) {
  return <span className={`djg-badge djg-badge-${tone}`}>{children}</span>;
}
