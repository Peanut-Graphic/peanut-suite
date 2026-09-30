import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { createColumnHelper } from '@tanstack/react-table';
import {
  RefreshCw,
  Trash2,
  Search,
  Filter,
  Eye,
  CheckCircle,
  XCircle,
  Clock,
  Loader2,
  Copy,
} from 'lucide-react';
import { Layout } from '../components/layout';
import {
  Card,
  Button,
  Input,
  Table,
  Pagination,
  Badge,
  Modal,
  ConfirmModal,
  Select,
  createCheckboxColumn,
} from '../components/common';
import { webhooksApi } from '../api/endpoints';
import SigningSecretsCard from '../components/webhooks/SigningSecretsCard';
import type { Webhook, WebhookStatus } from '../types';

const columnHelper = createColumnHelper<Webhook>();

const statusColors: Record<WebhookStatus, 'success' | 'warning' | 'danger' | 'default'> = {
  processed: 'success',
  pending: 'warning',
  processing: 'default',
  failed: 'danger',
};

const statusIcons: Record<WebhookStatus, typeof CheckCircle> = {
  processed: CheckCircle,
  pending: Clock,
  processing: Loader2,
  failed: XCircle,
};

export default function Webhooks() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [selectedRows, setSelectedRows] = useState<Record<string, boolean>>({});
  const [detailId, setDetailId] = useState<number | null>(null);
  const [deleteIds, setDeleteIds] = useState<number[]>([]);
  const [filters, setFilters] = useState({
    search: '',
    source: '',
    event: '',
    status: '',
  });
  const [showFilters, setShowFilters] = useState(false);

  // Fetch webhooks
  const { data, isLoading } = useQuery({
    queryKey: ['webhooks', page, filters],
    queryFn: () =>
      webhooksApi.getAll({
        page,
        per_page: 20,
        search: filters.search || undefined,
        source: filters.source || undefined,
        event: filters.event || undefined,
        status: filters.status || undefined,
      }),
  });

  // Fetch stats
  const { data: stats } = useQuery({
    queryKey: ['webhooks-stats'],
    queryFn: webhooksApi.getStats,
  });

  // Fetch filter options
  const { data: filterOptions } = useQuery({
    queryKey: ['webhooks-filters'],
    queryFn: webhooksApi.getFilters,
  });

  // Fetch single webhook for detail view
  const { data: detailWebhook, isLoading: detailLoading } = useQuery({
    queryKey: ['webhook', detailId],
    queryFn: () => (detailId ? webhooksApi.getById(detailId) : null),
    enabled: !!detailId,
  });

  // Reprocess mutation
  const reprocessMutation = useMutation({
    mutationFn: webhooksApi.reprocess,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['webhooks'] });
      queryClient.invalidateQueries({ queryKey: ['webhooks-stats'] });
    },
  });

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: webhooksApi.bulkDelete,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['webhooks'] });
      queryClient.invalidateQueries({ queryKey: ['webhooks-stats'] });
      setSelectedRows({});
      setDeleteIds([]);
    },
  });

  const selectedIds = Object.keys(selectedRows)
    .filter((key) => selectedRows[key])
    .map((key) => parseInt(key, 10));

  const columns = [
    createCheckboxColumn<Webhook>(),
    columnHelper.accessor('source', {
      header: 'Source',
      cell: (info) => (
        <Badge variant="default">{info.getValue()}</Badge>
      ),
    }),
    columnHelper.accessor('event', {
      header: 'Event',
      cell: (info) => (
        <span className="font-mono text-sm">{info.getValue()}</span>
      ),
    }),
    columnHelper.accessor('status', {
      header: 'Status',
      cell: (info) => {
        const status = info.getValue();
        const Icon = statusIcons[status];
        return (
          <div className="flex items-center gap-2">
            <Icon
              className={`w-4 h-4 ${
                status === 'processed'
                  ? 'text-green-500'
                  : status === 'failed'
                  ? 'text-red-500'
                  : status === 'pending'
                  ? 'text-yellow-500'
                  : 'text-slate-400 animate-spin'
              }`}
            />
            <Badge variant={statusColors[status]}>
              {status.charAt(0).toUpperCase() + status.slice(1)}
            </Badge>
          </div>
        );
      },
    }),
    columnHelper.accessor('retry_count', {
      header: 'Retries',
      cell: (info) => (
        <span className="text-slate-500">{info.getValue()}</span>
      ),
    }),
    columnHelper.accessor('created_at', {
      header: 'Received',
      cell: (info) => (
        <span className="text-sm text-slate-500">
          {new Date(info.getValue()).toLocaleString()}
        </span>
      ),
    }),
    columnHelper.display({
      id: 'actions',
      header: '',
      cell: (info) => {
        const webhook = info.row.original;
        return (
          <div className="flex items-center gap-2 justify-end">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setDetailId(webhook.id)}
              title="View Details"
            >
              <Eye className="w-4 h-4" />
            </Button>
            {(webhook.status === 'failed' || webhook.status === 'pending') && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => reprocessMutation.mutate(webhook.id)}
                disabled={reprocessMutation.isPending}
                title="Reprocess"
              >
                <RefreshCw className={`w-4 h-4 ${reprocessMutation.isPending ? 'animate-spin' : ''}`} />
              </Button>
            )}
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setDeleteIds([webhook.id])}
              title="Delete"
            >
              <Trash2 className="w-4 h-4 text-red-500" />
            </Button>
          </div>
        );
      },
    }),
  ];

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
  };

  // Build filter options
  const sourceOptions = [
    { value: '', label: 'All Sources' },
    ...(filterOptions?.sources.map((s) => ({ value: s, label: s })) || []),
  ];

  const eventOptions = [
    { value: '', label: 'All Events' },
    ...(filterOptions?.events.map((e) => ({ value: e, label: e })) || []),
  ];

  const statusOptions = [
    { value: '', label: 'All Statuses' },
    ...(filterOptions?.statuses.map((s) => ({
      value: s,
      label: s.charAt(0).toUpperCase() + s.slice(1),
    })) || []),
  ];

  return (
    <Layout
      title="Webhooks"
      description="View and manage incoming webhooks from FormFlow and other sources"
    >
      {/* Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
        <Card className="p-4">
          <div className="text-2xl font-bold">{stats?.total ?? 0}</div>
          <div className="text-sm text-slate-500">Total</div>
        </Card>
        <Card className="p-4">
          <div className="text-2xl font-bold text-green-600">{stats?.processed ?? 0}</div>
          <div className="text-sm text-slate-500">Processed</div>
        </Card>
        <Card className="p-4">
          <div className="text-2xl font-bold text-yellow-600">{stats?.pending ?? 0}</div>
          <div className="text-sm text-slate-500">Pending</div>
        </Card>
        <Card className="p-4">
          <div className="text-2xl font-bold text-red-600">{stats?.failed ?? 0}</div>
          <div className="text-sm text-slate-500">Failed</div>
        </Card>
        <Card className="p-4">
          <div className="text-2xl font-bold text-blue-600">{stats?.today ?? 0}</div>
          <div className="text-sm text-slate-500">Today</div>
        </Card>
        <Card className="p-4">
          <div className="text-2xl font-bold text-purple-600">
            {stats?.total && stats?.processed
              ? Math.round((stats.processed / stats.total) * 100)
              : 0}%
          </div>
          <div className="text-sm text-slate-500">Success Rate</div>
        </Card>
      </div>

      {/* Per-source signing secrets + unsigned-source status */}
      <SigningSecretsCard />

      {/* Filters */}
      <Card className="mb-6">
        <div className="p-4 border-b border-slate-100">
          <div className="flex items-center gap-4">
            <div className="flex-1 relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
              <Input
                placeholder="Search webhooks..."
                value={filters.search}
                onChange={(e) => setFilters({ ...filters, search: e.target.value })}
                className="pl-10"
              />
            </div>
            <Button
              variant="outline"
              onClick={() => setShowFilters(!showFilters)}
            >
              <Filter className="w-4 h-4 mr-2" />
              Filters
            </Button>
            {selectedIds.length > 0 && (
              <Button
                variant="danger"
                onClick={() => setDeleteIds(selectedIds)}
              >
                <Trash2 className="w-4 h-4 mr-2" />
                Delete ({selectedIds.length})
              </Button>
            )}
          </div>

          {showFilters && (
            <div className="grid grid-cols-3 gap-4 mt-4 pt-4 border-t border-slate-100">
              <Select
                value={filters.source}
                onChange={(e) => setFilters({ ...filters, source: e.target.value })}
                options={sourceOptions}
                placeholder="All Sources"
              />
              <Select
                value={filters.event}
                onChange={(e) => setFilters({ ...filters, event: e.target.value })}
                options={eventOptions}
                placeholder="All Events"
              />
              <Select
                value={filters.status}
                onChange={(e) => setFilters({ ...filters, status: e.target.value })}
                options={statusOptions}
                placeholder="All Statuses"
              />
            </div>
          )}
        </div>

        {/* Table */}
        <Table
          data={data?.data ?? []}
          columns={columns}
          loading={isLoading}
          rowSelection={selectedRows}
          onRowSelectionChange={setSelectedRows}
          emptyState={
            <div className="text-center py-12">
              <p className="text-slate-500">No webhooks received yet</p>
              <p className="text-sm text-slate-400 mt-1">
                Webhooks from FormFlow and other sources will appear here
              </p>
            </div>
          }
        />

        {/* Pagination */}
        {data && data.total_pages > 1 && (
          <div className="p-4 border-t border-slate-100">
            <Pagination
              page={page}
              totalPages={data.total_pages}
              total={data.total}
              perPage={data.per_page}
              onPageChange={setPage}
            />
          </div>
        )}
      </Card>

      {/* Detail Modal */}
      <Modal
        isOpen={!!detailId}
        onClose={() => setDetailId(null)}
        title="Webhook Details"
        size="lg"
      >
        {detailLoading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="w-6 h-6 animate-spin text-slate-400" />
          </div>
        ) : detailWebhook ? (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-sm font-medium text-slate-500">Source</label>
                <div className="mt-1">
                  <Badge variant="default">{detailWebhook.source}</Badge>
                </div>
              </div>
              <div>
                <label className="text-sm font-medium text-slate-500">Event</label>
                <div className="mt-1 font-mono text-sm">{detailWebhook.event}</div>
              </div>
              <div>
                <label className="text-sm font-medium text-slate-500">Status</label>
                <div className="mt-1">
                  <Badge variant={statusColors[detailWebhook.status]}>
                    {detailWebhook.status}
                  </Badge>
                </div>
              </div>
              <div>
                <label className="text-sm font-medium text-slate-500">Received</label>
                <div className="mt-1 text-sm">
                  {new Date(detailWebhook.created_at).toLocaleString()}
                </div>
              </div>
              {detailWebhook.processed_at && (
                <div>
                  <label className="text-sm font-medium text-slate-500">Processed</label>
                  <div className="mt-1 text-sm">
                    {new Date(detailWebhook.processed_at).toLocaleString()}
                  </div>
                </div>
              )}
              <div>
                <label className="text-sm font-medium text-slate-500">IP Address</label>
                <div className="mt-1 text-sm font-mono">
                  {detailWebhook.ip_address || 'N/A'}
                </div>
              </div>
            </div>

            {detailWebhook.error_message && (
              <div>
                <label className="text-sm font-medium text-slate-500">Error</label>
                <div className="mt-1 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
                  {detailWebhook.error_message}
                </div>
              </div>
            )}

            <div>
              <div className="flex items-center justify-between mb-2">
                <label className="text-sm font-medium text-slate-500">Payload</label>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => copyToClipboard(JSON.stringify(detailWebhook.payload, null, 2))}
                >
                  <Copy className="w-4 h-4 mr-1" />
                  Copy
                </Button>
              </div>
              <pre className="p-4 bg-slate-900 text-slate-100 rounded-lg text-xs overflow-auto max-h-96">
                {JSON.stringify(detailWebhook.payload, null, 2)}
              </pre>
            </div>

            <div className="flex justify-end gap-2 pt-4 border-t border-slate-100">
              {(detailWebhook.status === 'failed' || detailWebhook.status === 'pending') && (
                <Button
                  variant="outline"
                  onClick={() => {
                    reprocessMutation.mutate(detailWebhook.id);
                    setDetailId(null);
                  }}
                  disabled={reprocessMutation.isPending}
                >
                  <RefreshCw className="w-4 h-4 mr-2" />
                  Reprocess
                </Button>
              )}
              <Button variant="secondary" onClick={() => setDetailId(null)}>
                Close
              </Button>
            </div>
          </div>
        ) : null}
      </Modal>

      {/* Delete Confirmation */}
      <ConfirmModal
        isOpen={deleteIds.length > 0}
        onClose={() => setDeleteIds([])}
        onConfirm={() => bulkDeleteMutation.mutate(deleteIds)}
        title={deleteIds.length === 1 ? 'Delete Webhook' : `Delete ${deleteIds.length} Webhooks`}
        message={
          deleteIds.length === 1
            ? 'Are you sure you want to delete this webhook? This action cannot be undone.'
            : `Are you sure you want to delete ${deleteIds.length} webhooks? This action cannot be undone.`
        }
        confirmText="Delete"
        loading={bulkDeleteMutation.isPending}
      />
    </Layout>
  );
}
