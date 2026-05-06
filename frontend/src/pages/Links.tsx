import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { createColumnHelper } from '@tanstack/react-table';
import {
  Plus,
  Copy,
  Trash2,
  ExternalLink,
  Search,
  QrCode,
  BarChart2,
  Link2,
  Download,
} from 'lucide-react';
import { Layout } from '../components/layout';
import {
  Card,
  Button,
  Input,
  Table,
  Pagination,
  StatusBadge,
  Modal,
  ConfirmModal,
  QRCodeModal,
  createCheckboxColumn,
} from '../components/common';
import { linksApi } from '../api/endpoints';
import type { Link as LinkType } from '../types';
import { useFilterStore } from '../store';
import { exportToCSV, linksExportColumns } from '../utils';

const columnHelper = createColumnHelper<LinkType>();

export default function Links() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [selectedRows, setSelectedRows] = useState<Record<string, boolean>>({});
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const [qrLink, setQrLink] = useState<LinkType | null>(null);

  // Form state
  const [newLink, setNewLink] = useState({
    original_url: '',
    slug: '',
    title: '',
  });

  const { linkFilters, setLinkFilter } = useFilterStore();

  const { data, isLoading } = useQuery({
    queryKey: ['links', page, linkFilters],
    queryFn: () =>
      linksApi.getAll({
        page,
        per_page: 20,
        search: linkFilters.search || undefined,
        status: linkFilters.status || undefined,
      }),
  });

  const createMutation = useMutation({
    mutationFn: linksApi.create,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['links'] });
      setCreateModalOpen(false);
      setNewLink({ original_url: '', slug: '', title: '' });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: linksApi.delete,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['links'] });
      setDeleteId(null);
    },
  });

  const handleCopy = async (link: LinkType) => {
    await navigator.clipboard.writeText(link.short_url);
    setCopiedId(link.id);
    setTimeout(() => setCopiedId(null), 2000);
  };

  const handleCreate = () => {
    createMutation.mutate({
      original_url: newLink.original_url,
      slug: newLink.slug || undefined,
      title: newLink.title || undefined,
    });
  };

  const columns = [
    createCheckboxColumn<LinkType>(),
    columnHelper.accessor('title', {
      header: 'Link',
      cell: (info) => (
        <div>
          <p className="font-medium text-slate-900">
            {info.getValue() || 'Untitled'}
          </p>
          <p className="text-xs text-primary-600 truncate max-w-xs">
            {info.row.original.short_url}
          </p>
        </div>
      ),
    }),
    columnHelper.accessor('original_url', {
      header: 'Destination',
      cell: (info) => (
        <p className="text-sm text-slate-500 truncate max-w-xs">{info.getValue()}</p>
      ),
    }),
    columnHelper.accessor('status', {
      header: 'Status',
      cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    columnHelper.accessor('click_count', {
      header: 'Clicks',
      cell: (info) => (
        <div className="flex items-center gap-2">
          <BarChart2 className="w-4 h-4 text-slate-400" />
          <span className="font-medium text-slate-900">{info.getValue()}</span>
        </div>
      ),
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
            onClick={() => handleCopy(info.row.original)}
            className="p-1.5 text-slate-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors"
            title="Copy short URL"
          >
            {copiedId === info.row.original.id ? (
              <span className="text-xs text-green-600">Copied!</span>
            ) : (
              <Copy className="w-4 h-4" />
            )}
          </button>
          <button
            onClick={() => setQrLink(info.row.original)}
            className="p-1.5 text-slate-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors"
            title="QR Code"
          >
            <QrCode className="w-4 h-4" />
          </button>
          <a
            href={info.row.original.short_url}
            target="_blank"
            rel="noopener noreferrer"
            className="p-1.5 text-slate-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors"
            title="Open link"
          >
            <ExternalLink className="w-4 h-4" />
          </a>
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

  return (
    <Layout title="Short Links" description="Create and manage branded short links">
      {/* Header Actions */}
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-4">
          <div className="relative w-72">
            <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 z-10" />
            <Input
              placeholder="Search links..."
              value={linkFilters.search}
              onChange={(e) => setLinkFilter('search', e.target.value)}
              className="pl-10"
            />
          </div>
        </div>

        <div className="flex items-center gap-3">
          <Button
            variant="outline"
            icon={<Download className="w-4 h-4" />}
            onClick={() => data?.data && exportToCSV(data.data, linksExportColumns, 'links')}
            disabled={!data?.data?.length}
          >
            Export CSV
          </Button>
          <Button
            icon={<Plus className="w-4 h-4" />}
            onClick={() => setCreateModalOpen(true)}
          >
            Create Link
          </Button>
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <Card className="!p-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-primary-100 rounded-lg flex items-center justify-center">
              <Link2 className="w-5 h-5 text-primary-600" />
            </div>
            <div>
              <p className="text-2xl font-bold text-slate-900">{data?.total || 0}</p>
              <p className="text-sm text-slate-500">Total Links</p>
            </div>
          </div>
        </Card>
        <Card className="!p-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
              <BarChart2 className="w-5 h-5 text-green-600" />
            </div>
            <div>
              <p className="text-2xl font-bold text-slate-900">
                {data?.data?.reduce((acc, link) => acc + link.click_count, 0) || 0}
              </p>
              <p className="text-sm text-slate-500">Total Clicks</p>
            </div>
          </div>
        </Card>
        <Card className="!p-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
              <QrCode className="w-5 h-5 text-blue-600" />
            </div>
            <div>
              <p className="text-2xl font-bold text-slate-900">
                {data?.data?.filter((l) => l.status === 'active').length || 0}
              </p>
              <p className="text-sm text-slate-500">Active Links</p>
            </div>
          </div>
        </Card>
      </div>

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

      {/* Create Modal */}
      <Modal
        isOpen={createModalOpen}
        onClose={() => setCreateModalOpen(false)}
        title="Create Short Link"
        size="md"
      >
        <div className="space-y-4">
          <Input
            label="Destination URL"
            placeholder="https://example.com/your-long-url"
            value={newLink.original_url}
            onChange={(e) => setNewLink({ ...newLink, original_url: e.target.value })}
            required
          />
          <Input
            label="Custom Slug"
            placeholder="my-custom-slug"
            value={newLink.slug}
            onChange={(e) => setNewLink({ ...newLink, slug: e.target.value })}
            helper="Leave empty for auto-generated slug"
          />
          <Input
            label="Title"
            placeholder="Link title for reference"
            value={newLink.title}
            onChange={(e) => setNewLink({ ...newLink, title: e.target.value })}
          />
        </div>
        <div className="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-200">
          <Button variant="outline" onClick={() => setCreateModalOpen(false)}>
            Cancel
          </Button>
          <Button
            onClick={handleCreate}
            loading={createMutation.isPending}
            disabled={!newLink.original_url}
          >
            Create Link
          </Button>
        </div>
      </Modal>

      {/* Delete Confirmation */}
      <ConfirmModal
        isOpen={deleteId !== null}
        onClose={() => setDeleteId(null)}
        onConfirm={() => deleteId && deleteMutation.mutate(deleteId)}
        title="Delete Link"
        message="Are you sure you want to delete this link? This will break any existing references to this short URL."
        confirmText="Delete"
        variant="danger"
        loading={deleteMutation.isPending}
      />

      {/* QR Code Modal */}
      <QRCodeModal
        isOpen={qrLink !== null}
        onClose={() => setQrLink(null)}
        value={qrLink?.short_url || ''}
        title={qrLink?.title || 'Short Link'}
      />
    </Layout>
  );
}
