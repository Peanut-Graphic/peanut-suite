import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { createColumnHelper } from '@tanstack/react-table';
import {
  Plus,
  Trash2,
  Edit2,
  Copy,
  Eye,
  Search,
  BarChart2,
  MousePointer,
  Target,
} from 'lucide-react';
import { Layout } from '../components/layout';
import {
  Card,
  Button,
  Input,
  Table,
  Pagination,
  Badge,
  StatusBadge,
  ConfirmModal,
  createCheckboxColumn,
} from '../components/common';
import { popupsApi } from '../api/endpoints';
import type { Popup } from '../types';
import { useFilterStore } from '../store';

const columnHelper = createColumnHelper<Popup>();

const typeLabels: Record<string, string> = {
  modal: 'Modal',
  'slide-in': 'Slide In',
  bar: 'Bar',
  fullscreen: 'Fullscreen',
};

const triggerLabels: Record<string, string> = {
  time_delay: 'Time Delay',
  scroll_percent: 'Scroll %',
  exit_intent: 'Exit Intent',
  click: 'Click',
  page_views: 'Page Views',
};

export default function Popups() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [selectedRows, setSelectedRows] = useState<Record<string, boolean>>({});
  const [deleteId, setDeleteId] = useState<number | null>(null);

  const { popupFilters, setPopupFilter } = useFilterStore();

  const { data, isLoading } = useQuery({
    queryKey: ['popups', page, popupFilters],
    queryFn: () =>
      popupsApi.getAll({
        page,
        per_page: 20,
        search: popupFilters.search || undefined,
        status: popupFilters.status || undefined,
        type: popupFilters.type || undefined,
      }),
  });

  const deleteMutation = useMutation({
    mutationFn: popupsApi.delete,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['popups'] });
      setDeleteId(null);
    },
  });

  const columns = [
    createCheckboxColumn<Popup>(),
    columnHelper.accessor('name', {
      header: 'Popup',
      cell: (info) => (
        <div>
          <p className="font-medium text-slate-900">{info.getValue()}</p>
          <p className="text-xs text-slate-500">
            {typeLabels[info.row.original.type] || info.row.original.type}
          </p>
        </div>
      ),
    }),
    columnHelper.accessor('status', {
      header: 'Status',
      cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    columnHelper.display({
      id: 'trigger',
      header: 'Trigger',
      cell: (info) => {
        const triggerType = info.row.original.triggers?.type || '';
        return (
          <Badge variant="info">
            {triggerLabels[triggerType] || triggerType || 'None'}
          </Badge>
        );
      },
    }),
    columnHelper.accessor('views', {
      header: 'Views',
      cell: (info) => (
        <div className="flex items-center gap-2">
          <Eye className="w-4 h-4 text-slate-400" />
          <span className="text-slate-700">{info.getValue()}</span>
        </div>
      ),
    }),
    columnHelper.accessor('conversions', {
      header: 'Conversions',
      cell: (info) => (
        <div className="flex items-center gap-2">
          <Target className="w-4 h-4 text-slate-400" />
          <span className="text-slate-700">{info.getValue()}</span>
        </div>
      ),
    }),
    columnHelper.display({
      id: 'rate',
      header: 'Conv. Rate',
      cell: (info) => {
        const views = info.row.original.views || 0;
        const conversions = info.row.original.conversions || 0;
        const rate = views > 0 ? ((conversions / views) * 100).toFixed(1) : '0.0';
        return (
          <span className="font-medium text-slate-900">{rate}%</span>
        );
      },
    }),
    columnHelper.accessor('created_at', {
      header: 'Created',
      cell: (info) => (
        <span className="text-slate-500">
          {new Date(info.getValue()).toLocaleDateString()}
        </span>
      ),
    }),
    columnHelper.display({
      id: 'actions',
      header: '',
      cell: (info) => (
        <div className="flex items-center gap-1">
          <button
            className="p-1.5 text-slate-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors"
            title="Preview"
          >
            <Eye className="w-4 h-4" />
          </button>
          <button
            onClick={() => navigate(`/popups/${info.row.original.id}/edit`)}
            className="p-1.5 text-slate-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors"
            title="Edit"
          >
            <Edit2 className="w-4 h-4" />
          </button>
          <button
            className="p-1.5 text-slate-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors"
            title="Duplicate"
          >
            <Copy className="w-4 h-4" />
          </button>
          <button
            onClick={() => setDeleteId(info.row.original.id)}
            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
            title="Delete"
          >
            <Trash2 className="w-4 h-4" />
          </button>
        </div>
      ),
    }),
  ];

  // Calculate stats
  const totalViews = data?.data?.reduce((acc, p) => acc + (p.views || 0), 0) || 0;
  const totalConversions = data?.data?.reduce((acc, p) => acc + (p.conversions || 0), 0) || 0;
  const avgRate = totalViews > 0 ? ((totalConversions / totalViews) * 100).toFixed(1) : '0.0';

  return (
    <Layout title="Popups" description="Create and manage conversion popups">
      {/* Header Actions */}
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-4">
          <div className="relative w-72">
            <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 z-10" />
            <Input
              placeholder="Search popups..."
              value={popupFilters.search}
              onChange={(e) => setPopupFilter('search', e.target.value)}
              className="pl-10"
            />
          </div>
        </div>

        <Link to="/popups/new">
          <Button icon={<Plus className="w-4 h-4" />}>Create Popup</Button>
        </Link>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <Card className="!p-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-primary-100 rounded-lg flex items-center justify-center">
              <MousePointer className="w-5 h-5 text-primary-600" />
            </div>
            <div>
              <p className="text-2xl font-bold text-slate-900">{data?.total || 0}</p>
              <p className="text-sm text-slate-500">Total Popups</p>
            </div>
          </div>
        </Card>
        <Card className="!p-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
              <Eye className="w-5 h-5 text-green-600" />
            </div>
            <div>
              <p className="text-2xl font-bold text-slate-900">{totalViews}</p>
              <p className="text-sm text-slate-500">Total Views</p>
            </div>
          </div>
        </Card>
        <Card className="!p-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
              <Target className="w-5 h-5 text-blue-600" />
            </div>
            <div>
              <p className="text-2xl font-bold text-slate-900">{totalConversions}</p>
              <p className="text-sm text-slate-500">Conversions</p>
            </div>
          </div>
        </Card>
        <Card className="!p-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center">
              <BarChart2 className="w-5 h-5 text-amber-600" />
            </div>
            <div>
              <p className="text-2xl font-bold text-slate-900">{avgRate}%</p>
              <p className="text-sm text-slate-500">Avg. Conv. Rate</p>
            </div>
          </div>
        </Card>
      </div>

      {/* Filters */}
      <Card className="mb-6">
        <div className="flex flex-wrap gap-4">
          <div className="flex-1 min-w-[150px]">
            <label className="block text-xs font-medium text-slate-500 mb-1">Status</label>
            <select
              className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900"
              value={popupFilters.status}
              onChange={(e) => setPopupFilter('status', e.target.value)}
            >
              <option value="">All Statuses</option>
              <option value="active">Active</option>
              <option value="draft">Draft</option>
              <option value="paused">Paused</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <div className="flex-1 min-w-[150px]">
            <label className="block text-xs font-medium text-slate-500 mb-1">Type</label>
            <select
              className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900"
              value={popupFilters.type}
              onChange={(e) => setPopupFilter('type', e.target.value)}
            >
              <option value="">All Types</option>
              <option value="modal">Modal</option>
              <option value="slide-in">Slide In</option>
              <option value="bar">Bar</option>
              <option value="fullscreen">Fullscreen</option>
            </select>
          </div>
        </div>
      </Card>

      {/* Table */}
      <Card>
        <Table
          data={data?.data || []}
          columns={columns}
          loading={isLoading}
          rowSelection={selectedRows}
          onRowSelectionChange={setSelectedRows}
        />
        {data && data.total_pages > 1 && (
          <Pagination
            page={page}
            totalPages={data.total_pages}
            total={data.total}
            perPage={20}
            onPageChange={setPage}
          />
        )}
      </Card>

      {/* Delete Confirmation */}
      <ConfirmModal
        isOpen={deleteId !== null}
        onClose={() => setDeleteId(null)}
        onConfirm={() => deleteId && deleteMutation.mutate(deleteId)}
        title="Delete Popup"
        message="Are you sure you want to delete this popup? All analytics data will be lost."
        confirmText="Delete"
        variant="danger"
        loading={deleteMutation.isPending}
      />
    </Layout>
  );
}
