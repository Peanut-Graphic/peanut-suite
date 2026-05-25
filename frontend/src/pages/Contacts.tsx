import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { createColumnHelper } from '@tanstack/react-table';
import {
  Plus,
  Trash2,
  Mail,
  Search,
  Filter,
  Download,
  Upload,
  User,
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
  Modal,
  ConfirmModal,
  Select,
  createCheckboxColumn,
} from '../components/common';
import { contactsApi } from '../api/endpoints';
import type { Contact, ContactStatus } from '../types';
import { useFilterStore } from '../store';
import { exportToCSV, contactsExportColumns } from '../utils';

const columnHelper = createColumnHelper<Contact>();

const statusOptions = [
  { value: 'lead', label: 'Lead' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'qualified', label: 'Qualified' },
  { value: 'customer', label: 'Customer' },
  { value: 'churned', label: 'Churned' },
];

export default function Contacts() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [selectedRows, setSelectedRows] = useState<Record<string, boolean>>({});
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [createModalOpen, setCreateModalOpen] = useState(false);

  const [newContact, setNewContact] = useState<{
    email: string;
    first_name: string;
    last_name: string;
    phone: string;
    company: string;
    status: ContactStatus;
  }>({
    email: '',
    first_name: '',
    last_name: '',
    phone: '',
    company: '',
    status: 'lead',
  });

  const { contactFilters, setContactFilter, resetContactFilters } = useFilterStore();

  const { data, isLoading } = useQuery({
    queryKey: ['contacts', page, contactFilters],
    queryFn: () =>
      contactsApi.getAll({
        page,
        per_page: 20,
        search: contactFilters.search || undefined,
        status: contactFilters.status || undefined,
        source: contactFilters.source || undefined,
      }),
  });

  const createMutation = useMutation({
    mutationFn: contactsApi.create,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['contacts'] });
      setCreateModalOpen(false);
      setNewContact({
        email: '',
        first_name: '',
        last_name: '',
        phone: '',
        company: '',
        status: 'lead',
      });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: contactsApi.delete,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['contacts'] });
      setDeleteId(null);
    },
  });

  const handleCreate = () => {
    createMutation.mutate({
      email: newContact.email,
      first_name: newContact.first_name || undefined,
      last_name: newContact.last_name || undefined,
      phone: newContact.phone || undefined,
      company: newContact.company || undefined,
      status: newContact.status,
    });
  };

  const columns = [
    createCheckboxColumn<Contact>(),
    columnHelper.accessor('email', {
      header: 'Contact',
      cell: (info) => (
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 bg-primary-100 rounded-full flex items-center justify-center">
            <User className="w-4 h-4 text-primary-600" />
          </div>
          <div>
            <p className="font-medium text-slate-900">
              {info.row.original.first_name || info.row.original.last_name
                ? `${info.row.original.first_name || ''} ${info.row.original.last_name || ''}`.trim()
                : 'No name'}
            </p>
            <p className="text-sm text-slate-500">{info.getValue()}</p>
          </div>
        </div>
      ),
    }),
    columnHelper.accessor('company', {
      header: 'Company',
      cell: (info) => (
        <span className="text-slate-700">{info.getValue() || '-'}</span>
      ),
    }),
    columnHelper.accessor('status', {
      header: 'Status',
      cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    columnHelper.accessor('source', {
      header: 'Source',
      cell: (info) => (
        <Badge variant="default">{info.getValue() || 'direct'}</Badge>
      ),
    }),
    columnHelper.accessor('tags', {
      header: 'Tags',
      cell: (info) => {
        const tags = info.getValue() || [];
        return tags.length > 0 ? (
          <div className="flex gap-1">
            {tags.slice(0, 2).map((tag) => (
              <Badge key={tag} variant="primary" size="sm">
                {tag}
              </Badge>
            ))}
            {tags.length > 2 && (
              <Badge variant="default" size="sm">
                +{tags.length - 2}
              </Badge>
            )}
          </div>
        ) : (
          <span className="text-slate-400">-</span>
        );
      },
    }),
    columnHelper.accessor('created_at', {
      header: 'Added',
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
          <a
            href={`mailto:${info.row.original.email}`}
            className="p-1.5 text-slate-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors"
            title="Send email"
          >
            <Mail className="w-4 h-4" />
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

  const selectedCount = Object.values(selectedRows).filter(Boolean).length;

  return (
    <Layout title="Contacts" description="Manage your contacts and leads">
      {/* Header Actions */}
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-4">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search contacts..."
              className="pl-10 pr-4 py-2 border border-slate-200 rounded-lg text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
              value={contactFilters.search}
              onChange={(e) => setContactFilter('search', e.target.value)}
            />
          </div>
          <Button
            variant="outline"
            size="sm"
            icon={<Filter className="w-4 h-4" />}
            onClick={resetContactFilters}
          >
            Clear Filters
          </Button>
        </div>

        <div className="flex items-center gap-3">
          {selectedCount > 0 && (
            <Button variant="danger" size="sm" icon={<Trash2 className="w-4 h-4" />}>
              Delete ({selectedCount})
            </Button>
          )}
          <Button
            variant="outline"
            size="sm"
            icon={<Download className="w-4 h-4" />}
            onClick={() => data?.data && exportToCSV(data.data, contactsExportColumns, 'contacts')}
            disabled={!data?.data?.length}
          >
            Export CSV
          </Button>
          <Button variant="outline" size="sm" icon={<Upload className="w-4 h-4" />}>
            Import
          </Button>
          <Button
            icon={<Plus className="w-4 h-4" />}
            onClick={() => setCreateModalOpen(true)}
          >
            Add Contact
          </Button>
        </div>
      </div>

      {/* Filters */}
      <Card className="mb-6">
        <div className="flex flex-wrap gap-4">
          <div className="flex-1 min-w-[150px]">
            <label className="block text-xs font-medium text-slate-500 mb-1" htmlFor="contacts-status">Status</label>
            <select id="contacts-status"
              className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900"
              value={contactFilters.status}
              onChange={(e) => setContactFilter('status', e.target.value)}
            >
              <option value="">All Statuses</option>
              {statusOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </div>
          <div className="flex-1 min-w-[150px]">
            <label className="block text-xs font-medium text-slate-500 mb-1" htmlFor="contacts-source">Source</label>
            <select id="contacts-source"
              className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900"
              value={contactFilters.source}
              onChange={(e) => setContactFilter('source', e.target.value)}
            >
              <option value="">All Sources</option>
              <option value="popup">Popup</option>
              <option value="form">Form</option>
              <option value="import">Import</option>
              <option value="manual">Manual</option>
            </select>
          </div>
          <div className="flex-1 min-w-[150px]">
            <label className="block text-xs font-medium text-slate-500 mb-1" htmlFor="contacts-tag">Tag</label>
            <select id="contacts-tag"
              className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900"
              value={contactFilters.tag}
              onChange={(e) => setContactFilter('tag', e.target.value)}
            >
              <option value="">All Tags</option>
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

      {/* Create Modal */}
      <Modal
        isOpen={createModalOpen}
        onClose={() => setCreateModalOpen(false)}
        title="Add Contact"
        size="md"
      >
        <div className="space-y-4">
          <Input
            label="Email"
            type="email"
            placeholder="contact@example.com"
            value={newContact.email}
            onChange={(e) => setNewContact({ ...newContact, email: e.target.value })}
            required
          />
          <div className="grid grid-cols-2 gap-4">
            <Input
              label="First Name"
              placeholder="John"
              value={newContact.first_name}
              onChange={(e) => setNewContact({ ...newContact, first_name: e.target.value })}
            />
            <Input
              label="Last Name"
              placeholder="Doe"
              value={newContact.last_name}
              onChange={(e) => setNewContact({ ...newContact, last_name: e.target.value })}
            />
          </div>
          <Input
            label="Phone"
            type="tel"
            placeholder="+1 (555) 000-0000"
            value={newContact.phone}
            onChange={(e) => setNewContact({ ...newContact, phone: e.target.value })}
          />
          <Input
            label="Company"
            placeholder="Acme Inc."
            value={newContact.company}
            onChange={(e) => setNewContact({ ...newContact, company: e.target.value })}
          />
          <Select
            label="Status"
            options={statusOptions}
            value={newContact.status}
            onChange={(e) => setNewContact({ ...newContact, status: e.target.value as ContactStatus })}
          />
        </div>
        <div className="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-200">
          <Button variant="outline" onClick={() => setCreateModalOpen(false)}>
            Cancel
          </Button>
          <Button
            onClick={handleCreate}
            loading={createMutation.isPending}
            disabled={!newContact.email}
          >
            Add Contact
          </Button>
        </div>
      </Modal>

      {/* Delete Confirmation */}
      <ConfirmModal
        isOpen={deleteId !== null}
        onClose={() => setDeleteId(null)}
        onConfirm={() => deleteId && deleteMutation.mutate(deleteId)}
        title="Delete Contact"
        message="Are you sure you want to delete this contact? This action cannot be undone."
        confirmText="Delete"
        variant="danger"
        loading={deleteMutation.isPending}
      />
    </Layout>
  );
}
