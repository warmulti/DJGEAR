import React from 'react';

export interface CardProps {
  title?: string;
  action?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
}

export function Card({ title, action, children, className = '' }: CardProps) {
  return (
    <section className={`djg-card ${className}`.trim()}>
      {(title || action) && (
        <div className="djg-card-header">
          {title && <h2 className="djg-card-title">{title}</h2>}
          {action}
        </div>
      )}
      {children}
    </section>
  );
}

export interface KpiCardProps {
  label: string;
  value: string | number;
  meta?: string;
  icon?: React.ReactNode;
}

export function KpiCard({ label, value, meta, icon }: KpiCardProps) {
  return (
    <div className="djg-card">
      <div className="djg-card-header" style={{ marginBottom: 4 }}>
        <span style={{ fontSize: 13, color: 'var(--djg-text-secondary)', fontWeight: 500 }}>{label}</span>
        {icon}
      </div>
      <div className="djg-kpi-value">{value}</div>
      {meta && <div className="djg-kpi-meta">{meta}</div>}
    </div>
  );
}
