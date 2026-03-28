import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { createColumnHelper } from '@tanstack/react-table';
import {
  Plus,
  Copy,
  Trash2,
  ExternalLink,
  Search,
  Filter,
  Download,
  Users,
  X,
  Check,
} from 'lucide-react';
import { clsx } from 'clsx';
import { Layout } from '../components/layout';
import {
  Card,
  Button,
  Table,
  Pagination,
  Badge,
  Modal,
  ConfirmModal,
  createCheckboxColumn,
} from '../components/common';
import { utmApi, utmAccessApi, accountsApi } from '../api/endpoints';
import type { UTM, UTMAccess, UTMAccessLevel } from '../types';
import { useFilterStore, useCurrentAccount, useIsAccountAdmin, toast } from '../store';
import { exportToCSV, utmExportColumns } from '../utils';

const columnHelper = createColumnHelper<UTM>();

export default function UTMLibrary() {
  const queryClient = useQueryClient();
  const currentAccount = useCurrentAccount();
  const isAdmin = useIsAccountAdmin();
  const accountId = currentAccount?.id || 0;

  const [page, setPage] = useState(1);
  const [selectedRows, setSelectedRows] = useState<Record<string, boolean>>({});
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [copiedId, setCopiedId] = useState<number | null>(null);

  // Assignment modal state
  const [assignModalOpen, setAssignModalOpen] = useState(false);
  const [assignUtm, setAssignUtm] = useState<UTM | null>(null);
  const [selectedUsers, setSelectedUsers] = useState<Record<number, UTMAccessLevel>>({});
  const [accessLevel, setAccessLevel] = useState<UTMAccessLevel>('view');

  const { utmFilters, setUTMFilter, resetUTMFilters } = useFilterStore();

  const { data, isLoading } = useQuery({
    queryKey: ['utms', page, utmFilters],
    queryFn: () =>
      utmApi.getAll({
        page,
        per_page: 20,
        search: utmFilters.search || undefined,
        utm_source: utmFilters.source || undefined,
        utm_medium: utmFilters.medium || undefined,
        utm_campaign: utmFilters.campaign || undefined,
        program: utmFilters.program || undefined,
      }),
  });

  const deleteMutation = useMutation({
    mutationFn: utmApi.delete,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['utms'] });
      setDeleteId(null);
    },
  });

  // Query for team members (for assignment modal)
  const { data: teamData } = useQuery({
    queryKey: ['team-members', accountId],
    queryFn: () => accountsApi.getMembers(accountId),
    enabled: !!accountId && isAdmin,
  });

  // Query for current UTM access when opening modal
  const { data: utmAccessData, isLoading: loadingAccess } = useQuery({
    queryKey: ['utm-access', assignUtm?.id],
    queryFn: () => (assignUtm ? utmAccessApi.getAccess(assignUtm.id) : Promise.resolve([])),
    enabled: !!assignUtm,
  });

  // Mutation for assigning users
  const assignUsersMutation = useMutation({
    mutationFn: ({ utmId, userIds, level }: { utmId: number; userIds: number[]; level: UTMAccessLevel }) =>
      utmAccessApi.assignUsers(utmId, userIds, level),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['utm-access'] });
      toast.success('Users assigned successfully');
      setAssignModalOpen(false);
      setAssignUtm(null);
      setSelectedUsers({});
    },
    onError: (error) => {
      toast.error(error instanceof Error ? error.message : 'Failed to assign users');
    },
  });

  // Mutation for revoking user access
  const revokeAccessMutation = useMutation({
    mutationFn: ({ utmId, userId }: { utmId: number; userId: number }) =>
      utmAccessApi.revokeAccess(utmId, userId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['utm-access'] });
      toast.success('Access revoked');
    },
    onError: (error) => {
      toast.error(error instanceof Error ? error.message : 'Failed to revoke access');
    },
  });

  // Filter out admins/owners from assignable members (they already have full access)
  const assignableMembers = (teamData?.members || []).filter(
    (m) => m.role === 'member' || m.role === 'viewer'
  );

  // Handle opening the assign modal
  const handleOpenAssignModal = (utm: UTM) => {
    setAssignUtm(utm);
    setSelectedUsers({});
    setAccessLevel('view');
    setAssignModalOpen(true);
  };

  // Handle saving assignments
  const handleSaveAssignments = () => {
    if (!assignUtm) return;
    const userIds = Object.keys(selectedUsers).map(Number);
    if (userIds.length === 0) {
      toast.error('Please select at least one user');
      return;
    }
    assignUsersMutation.mutate({
      utmId: assignUtm.id,
      userIds,
      level: accessLevel,
    });
  };

  const handleCopy = async (utm: UTM) => {
    await navigator.clipboard.writeText(utm.full_url);
    setCopiedId(utm.id);
    setTimeout(() => setCopiedId(null), 2000);
  };

  const columns = [
    createCheckboxColumn<UTM>(),
    columnHelper.accessor('utm_campaign', {
      header: 'Campaign',
      cell: (info) => (
        <div>
          <p className="font-medium text-slate-900">{info.getValue()}</p>
          <p className="text-xs text-slate-500 truncate max-w-xs">{info.row.original.base_url}</p>
        </div>
      ),
    }),
    columnHelper.accessor('utm_source', {
      header: 'Source',
      cell: (info) => <Badge variant="info">{info.getValue()}</Badge>,
    }),
    columnHelper.accessor('utm_medium', {
      header: 'Medium',
      cell: (info) => <Badge>{info.getValue()}</Badge>,
    }),
    columnHelper.accessor('click_count', {
      header: 'Clicks',
      cell: (info) => (
        <span className="font-medium text-slate-900">{info.getValue()}</span>
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
            title="Copy URL"
          >
            {copiedId === info.row.original.id ? (
              <span className="text-xs text-green-600">Copied!</span>
            ) : (
              <Copy className="w-4 h-4" />
            )}
          </button>
          <a
            href={info.row.original.full_url}
            target="_blank"
            rel="noopener noreferrer"
            className="p-1.5 text-slate-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors"
            title="Open URL"
          >
            <ExternalLink className="w-4 h-4" />
          </a>
          {/* Assign Users button - only for admins */}
          {isAdmin && (
            <button
              onClick={() => handleOpenAssignModal(info.row.original)}
              className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors"
              title="Assign Users"
            >
              <Users className="w-4 h-4" />
            </button>
          )}
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
    <Layout title="UTM Library" description="Manage your tracked URLs">
      {/* Header Actions */}
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-4">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search campaigns..."
              className="pl-10 pr-4 py-2 border border-slate-200 rounded-lg text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
              value={utmFilters.search}
              onChange={(e) => setUTMFilter('search', e.target.value)}
            />
          </div>
          <Button
            variant="outline"
            size="sm"
            icon={<Filter className="w-4 h-4" />}
            onClick={resetUTMFilters}
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
            onClick={() => data?.data && exportToCSV(data.data, utmExportColumns, 'utm-codes')}
            disabled={!data?.data?.length}
          >
            Export CSV
          </Button>
          <Link to="/utm">
            <Button icon={<Plus className="w-4 h-4" />}>Create UTM</Button>
          </Link>
        </div>
      </div>

      {/* Filters */}
      <Card className="mb-6">
        <div className="flex flex-wrap gap-4">
          <div className="flex-1 min-w-[150px]">
            <label className="block text-xs font-medium text-slate-500 mb-1">Source</label>
            <select
              className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900"
              value={utmFilters.source}
              onChange={(e) => setUTMFilter('source', e.target.value)}
            >
              <option value="">All Sources</option>
              <option value="google">Google</option>
              <option value="facebook">Facebook</option>
              <option value="instagram">Instagram</option>
              <option value="email">Email</option>
            </select>
          </div>
          <div className="flex-1 min-w-[150px]">
            <label className="block text-xs font-medium text-slate-500 mb-1">Medium</label>
            <select
              className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900"
              value={utmFilters.medium}
              onChange={(e) => setUTMFilter('medium', e.target.value)}
            >
              <option value="">All Mediums</option>
              <option value="cpc">CPC</option>
              <option value="organic">Organic</option>
              <option value="social">Social</option>
              <option value="email">Email</option>
            </select>
          </div>
          <div className="flex-1 min-w-[150px]">
            <label className="block text-xs font-medium text-slate-500 mb-1">Program</label>
            <select
              className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900"
              value={utmFilters.program}
              onChange={(e) => setUTMFilter('program', e.target.value)}
            >
              <option value="">All Programs</option>
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
        title="Delete UTM Code"
        message="Are you sure you want to delete this UTM code? This action cannot be undone."
        confirmText="Delete"
        variant="danger"
        loading={deleteMutation.isPending}
      />

      {/* Assign Users Modal */}
      <Modal
        isOpen={assignModalOpen}
        onClose={() => {
          setAssignModalOpen(false);
          setAssignUtm(null);
          setSelectedUsers({});
        }}
        title="Assign Users to UTM"
      >
        {assignUtm && (
          <div className="space-y-4">
            {/* UTM Info */}
            <div className="bg-slate-50 dark:bg-slate-800 rounded-lg p-3">
              <p className="font-medium text-slate-900 dark:text-slate-100">
                {assignUtm.utm_campaign}
              </p>
              <p className="text-sm text-slate-500 dark:text-slate-400 truncate">
                {assignUtm.base_url}
              </p>
            </div>

            {/* Currently Assigned Users */}
            {utmAccessData && utmAccessData.length > 0 && (
              <div>
                <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                  Currently Assigned
                </label>
                <div className="space-y-2 max-h-32 overflow-y-auto">
                  {utmAccessData.map((access: UTMAccess) => (
                    <div
                      key={access.user_id}
                      className="flex items-center justify-between p-2 bg-slate-50 dark:bg-slate-800 rounded-lg"
                    >
                      <div>
                        <p className="text-sm font-medium text-slate-900 dark:text-slate-100">
                          {access.user_name || access.user_email}
                        </p>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                          {access.access_level} access
                        </p>
                      </div>
                      <button
                        onClick={() =>
                          revokeAccessMutation.mutate({
                            utmId: assignUtm.id,
                            userId: access.user_id,
                          })
                        }
                        className="p-1 text-slate-400 hover:text-red-600 rounded"
                        title="Revoke access"
                      >
                        <X className="w-4 h-4" />
                      </button>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Access Level Selection */}
            <div>
              <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                Access Level
              </label>
              <select
                className="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-900"
                value={accessLevel}
                onChange={(e) => setAccessLevel(e.target.value as UTMAccessLevel)}
              >
                <option value="view">View Only</option>
                <option value="edit">Can Edit</option>
                <option value="full">Full Access</option>
              </select>
            </div>

            {/* User Selection */}
            <div>
              <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                Select Users to Assign
              </label>
              {loadingAccess ? (
                <p className="text-sm text-slate-500">Loading...</p>
              ) : assignableMembers.length === 0 ? (
                <p className="text-sm text-slate-500 dark:text-slate-400 p-4 text-center border border-dashed border-slate-300 dark:border-slate-700 rounded-lg">
                  No team members available to assign.
                  <br />
                  <span className="text-xs">Admins and owners already have full access.</span>
                </p>
              ) : (
                <div className="space-y-2 max-h-48 overflow-y-auto border border-slate-200 dark:border-slate-700 rounded-lg p-2">
                  {assignableMembers.map((member) => {
                    const isAlreadyAssigned = utmAccessData?.some(
                      (a: UTMAccess) => a.user_id === member.user_id
                    );
                    const isSelected = selectedUsers[member.user_id] !== undefined;

                    return (
                      <label
                        key={member.user_id}
                        className={clsx(
                          'flex items-center gap-3 p-2 rounded-md cursor-pointer transition-colors',
                          isAlreadyAssigned
                            ? 'opacity-50 cursor-not-allowed bg-slate-100 dark:bg-slate-800'
                            : isSelected
                            ? 'bg-primary-50 dark:bg-primary-900/20'
                            : 'hover:bg-slate-50 dark:hover:bg-slate-800'
                        )}
                      >
                        <input
                          type="checkbox"
                          checked={isSelected}
                          disabled={isAlreadyAssigned}
                          onChange={(e) => {
                            if (e.target.checked) {
                              setSelectedUsers({
                                ...selectedUsers,
                                [member.user_id]: accessLevel,
                              });
                            } else {
                              const { [member.user_id]: _, ...rest } = selectedUsers;
                              setSelectedUsers(rest);
                            }
                          }}
                          className="w-4 h-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500 disabled:opacity-50"
                        />
                        <div className="flex-1">
                          <p className="text-sm font-medium text-slate-900 dark:text-slate-100">
                            {member.display_name || member.user_login}
                          </p>
                          <p className="text-xs text-slate-500 dark:text-slate-400">
                            {member.user_email}
                          </p>
                        </div>
                        {isAlreadyAssigned && (
                          <span title="Already assigned">
                            <Check className="w-4 h-4 text-green-500" />
                          </span>
                        )}
                      </label>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Actions */}
            <div className="flex justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-700">
              <Button
                variant="outline"
                onClick={() => {
                  setAssignModalOpen(false);
                  setAssignUtm(null);
                  setSelectedUsers({});
                }}
              >
                Cancel
              </Button>
              <Button
                onClick={handleSaveAssignments}
                disabled={
                  Object.keys(selectedUsers).length === 0 || assignUsersMutation.isPending
                }
              >
                {assignUsersMutation.isPending
                  ? 'Assigning...'
                  : `Assign ${Object.keys(selectedUsers).length} User${Object.keys(selectedUsers).length !== 1 ? 's' : ''}`}
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </Layout>
  );
}
