import React from 'react';
import { EmptyState, ErrorState, LoadingState } from './StateFeedback';

export interface ColumnDef<T> {
  key: string;
  header: string;
  render: (row: T) => React.ReactNode;
}

export interface DataTableProps<T> {
  columns: Array<ColumnDef<T>>;
  rows: T[];
  rowKey: (row: T) => string | number;
  loading?: boolean;
  error?: string | null;
  onRetry?: () => void;
  emptyTitle?: string;
  emptyDescription?: string;
}

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  loading = false,
  error = null,
  onRetry,
  emptyTitle,
  emptyDescription,
}: DataTableProps<T>) {
  if (loading) {
    return <LoadingState />;
  }

  if (error) {
    return <ErrorState message={error} onRetry={onRetry} />;
  }

  if (rows.length === 0) {
    return <EmptyState title={emptyTitle} description={emptyDescription} />;
  }

  return (
    <div className="djg-table-wrap">
      <table className="djg-table">
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key}>{col.header}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={rowKey(row)}>
              {columns.map((col) => (
                <td key={col.key}>{col.render(row)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
