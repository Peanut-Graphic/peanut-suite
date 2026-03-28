import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  ArrowLeft,
  RefreshCw,
  ExternalLink,
  Server,
  Shield,
  Database,
  HardDrive,
  Activity,
  Clock,
  CheckCircle,
  Download,
} from 'lucide-react';
import { Layout } from '../components/layout';
import { Card, Button, Badge } from '../components/common';
import { monitorApi } from '../api/endpoints';

function getHealthColor(score: number) {
  if (score >= 80) return 'text-green-500';
  if (score >= 50) return 'text-amber-500';
  return 'text-red-500';
}

export default function SiteDetail() {
  const { id } = useParams();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState<'overview' | 'updates' | 'uptime' | 'settings'>('overview');

  const { data: site, isLoading } = useQuery({
    queryKey: ['monitor-site', id],
    queryFn: () => monitorApi.getSite(Number(id)),
  });

  const { data: health } = useQuery({
    queryKey: ['monitor-site-health', id],
    queryFn: () => monitorApi.getSiteHealth(Number(id)),
    enabled: !!id,
  });

  const { data: uptime } = useQuery({
    queryKey: ['monitor-site-uptime', id],
    queryFn: () => monitorApi.getSiteUptime(Number(id)),
    enabled: !!id && activeTab === 'uptime',
  });

  const refreshMutation = useMutation({
    mutationFn: () => monitorApi.refreshSite(Number(id)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['monitor-site', id] });
      queryClient.invalidateQueries({ queryKey: ['monitor-site-health', id] });
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ type, items }: { type: string; items: string[] }) =>
      monitorApi.runUpdates(Number(id), type, items),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['monitor-site', id] });
    },
  });

  if (isLoading) {
    return (
      <Layout title="Loading..." description="">
        <div className="animate-pulse space-y-4">
          <div className="h-10 bg-slate-200 rounded w-1/3" />
          <div className="h-64 bg-slate-200 rounded" />
        </div>
      </Layout>
    );
  }

  if (!site) {
    return (
      <Layout title="Site Not Found" description="">
        <Card>
          <p className="text-slate-600">The requested site could not be found.</p>
          <Link to="/monitor" className="text-primary-600 hover:underline">
            Back to Monitor
          </Link>
        </Card>
      </Layout>
    );
  }

  const healthScore = site.health_score || 0;

  return (
    <Layout
      title={site.name || 'Site Details'}
      description={site.url}
    >
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <Link to="/monitor">
          <Button variant="outline" icon={<ArrowLeft className="w-4 h-4" />}>
            Back to Monitor
          </Button>
        </Link>
        <div className="flex items-center gap-3">
          <Button
            variant="outline"
            onClick={() => refreshMutation.mutate()}
            loading={refreshMutation.isPending}
            icon={<RefreshCw className="w-4 h-4" />}
          >
            Refresh
          </Button>
          <a href={site.url} target="_blank" rel="noopener noreferrer">
            <Button variant="outline" icon={<ExternalLink className="w-4 h-4" />}>
              Visit Site
            </Button>
          </a>
        </div>
      </div>

      {/* Health Score Card */}
      <Card className="mb-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-6">
            <div className="relative w-24 h-24">
              <svg className="w-full h-full transform -rotate-90">
                <circle
                  cx="48"
                  cy="48"
                  r="40"
                  fill="none"
                  stroke="#e2e8f0"
                  strokeWidth="8"
                />
                <circle
                  cx="48"
                  cy="48"
                  r="40"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="8"
                  strokeLinecap="round"
                  strokeDasharray={`${(healthScore / 100) * 251.2} 251.2`}
                  className={getHealthColor(healthScore)}
                />
              </svg>
              <div className="absolute inset-0 flex items-center justify-center">
                <span className="text-2xl font-bold text-slate-900">{healthScore}</span>
              </div>
            </div>
            <div>
              <h3 className="text-xl font-semibold text-slate-900">Health Score</h3>
              <p className="text-slate-500">
                {healthScore >= 80
                  ? 'Site is healthy and running well'
                  : healthScore >= 50
                  ? 'Some issues need attention'
                  : 'Critical issues detected'}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-4 text-sm">
            <div className="text-center">
              <p className="text-2xl font-bold text-slate-900">{site.uptime_percent?.toFixed(2) || '100.00'}%</p>
              <p className="text-slate-500">Uptime (30d)</p>
            </div>
            <div className="text-center">
              <p className="text-2xl font-bold text-slate-900">{site.updates_available || 0}</p>
              <p className="text-slate-500">Updates</p>
            </div>
          </div>
        </div>
      </Card>

      {/* Tabs */}
      <div className="flex gap-1 bg-slate-100 p-1 rounded-lg mb-6">
        {[
          { id: 'overview', label: 'Overview' },
          { id: 'updates', label: 'Updates' },
          { id: 'uptime', label: 'Uptime' },
          { id: 'settings', label: 'Settings' },
        ].map((tab) => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id as typeof activeTab)}
            className={`flex-1 px-4 py-2 rounded-md text-sm font-medium transition-colors ${
              activeTab === tab.id
                ? 'bg-white text-slate-900 shadow-sm'
                : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Tab Content */}
      {activeTab === 'overview' && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <Card>
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <Server className="w-5 h-5 text-blue-600" />
              </div>
              <h3 className="font-semibold text-slate-900">System Info</h3>
            </div>
            <dl className="space-y-3">
              <div className="flex justify-between">
                <dt className="text-slate-500">WordPress</dt>
                <dd className="font-medium text-slate-900">{site.wp_version || '-'}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-slate-500">PHP</dt>
                <dd className="font-medium text-slate-900">{site.php_version || '-'}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-slate-500">MySQL</dt>
                <dd className="font-medium text-slate-900">{health?.mysql_version || '-'}</dd>
              </div>
            </dl>
          </Card>

          <Card>
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <Shield className="w-5 h-5 text-green-600" />
              </div>
              <h3 className="font-semibold text-slate-900">Security</h3>
            </div>
            <dl className="space-y-3">
              <div className="flex justify-between items-center">
                <dt className="text-slate-500">SSL Certificate</dt>
                <dd>
                  {site.ssl_enabled ? (
                    <Badge variant="success">Active</Badge>
                  ) : (
                    <Badge variant="danger">Not Found</Badge>
                  )}
                </dd>
              </div>
              <div className="flex justify-between items-center">
                <dt className="text-slate-500">Debug Mode</dt>
                <dd>
                  {health?.debug_mode ? (
                    <Badge variant="warning">Enabled</Badge>
                  ) : (
                    <Badge variant="success">Disabled</Badge>
                  )}
                </dd>
              </div>
            </dl>
          </Card>

          <Card>
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center">
                <HardDrive className="w-5 h-5 text-amber-600" />
              </div>
              <h3 className="font-semibold text-slate-900">Storage</h3>
            </div>
            <dl className="space-y-3">
              <div className="flex justify-between">
                <dt className="text-slate-500">Disk Used</dt>
                <dd className="font-medium text-slate-900">{health?.disk_used || '-'}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-slate-500">Disk Free</dt>
                <dd className="font-medium text-slate-900">{health?.disk_free || '-'}</dd>
              </div>
              <div className="w-full bg-slate-200 rounded-full h-2 mt-2">
                <div
                  className="bg-amber-500 h-2 rounded-full"
                  style={{ width: `${health?.disk_percent || 0}%` }}
                />
              </div>
            </dl>
          </Card>

          <Card>
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                <Database className="w-5 h-5 text-purple-600" />
              </div>
              <h3 className="font-semibold text-slate-900">Plugins</h3>
            </div>
            <dl className="space-y-3">
              <div className="flex justify-between">
                <dt className="text-slate-500">Active</dt>
                <dd className="font-medium text-slate-900">{health?.plugins_active || 0}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-slate-500">Inactive</dt>
                <dd className="font-medium text-slate-900">{health?.plugins_inactive || 0}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-slate-500">Updates</dt>
                <dd className="font-medium text-slate-900">{health?.plugins_updates || 0}</dd>
              </div>
            </dl>
          </Card>

          <Card>
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
                <Activity className="w-5 h-5 text-indigo-600" />
              </div>
              <h3 className="font-semibold text-slate-900">Performance</h3>
            </div>
            <dl className="space-y-3">
              <div className="flex justify-between">
                <dt className="text-slate-500">Memory Limit</dt>
                <dd className="font-medium text-slate-900">{health?.memory_limit || '-'}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-slate-500">Max Execution</dt>
                <dd className="font-medium text-slate-900">{health?.max_execution_time || '-'}s</dd>
              </div>
            </dl>
          </Card>

          <Card>
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 bg-slate-100 rounded-lg flex items-center justify-center">
                <Clock className="w-5 h-5 text-slate-600" />
              </div>
              <h3 className="font-semibold text-slate-900">Last Backup</h3>
            </div>
            <p className="text-slate-600">
              {health?.last_backup
                ? new Date(health.last_backup).toLocaleString()
                : 'No backup detected'}
            </p>
          </Card>
        </div>
      )}

      {activeTab === 'updates' && (
        <Card>
          <div className="flex items-center justify-between mb-6">
            <h3 className="text-lg font-semibold text-slate-900">Available Updates</h3>
            <Button
              size="sm"
              onClick={() => updateMutation.mutate({ type: 'all', items: [] })}
              loading={updateMutation.isPending}
              icon={<Download className="w-4 h-4" />}
            >
              Update All
            </Button>
          </div>
          {health?.plugin_updates?.length ? (
            <div className="space-y-3">
              {health.plugin_updates.map((plugin: { slug: string; name: string; current: string; new: string }) => (
                <div key={plugin.slug} className="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                  <div>
                    <p className="font-medium text-slate-900">{plugin.name}</p>
                    <p className="text-sm text-slate-500">
                      {plugin.current} → {plugin.new}
                    </p>
                  </div>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => updateMutation.mutate({ type: 'plugin', items: [plugin.slug] })}
                    loading={updateMutation.isPending}
                  >
                    Update
                  </Button>
                </div>
              ))}
            </div>
          ) : (
            <div className="text-center py-8 text-slate-500">
              <CheckCircle className="w-12 h-12 mx-auto mb-2 text-green-500" />
              <p>All plugins are up to date!</p>
            </div>
          )}
        </Card>
      )}

      {activeTab === 'uptime' && (
        <Card>
          <h3 className="text-lg font-semibold text-slate-900 mb-6">Uptime History (30 days)</h3>
          <div className="flex gap-1 mb-4">
            {Array.from({ length: 30 }).map((_, i) => {
              const day = uptime?.daily?.[i];
              const uptimeValue = day?.uptime ?? 100;
              const isUp = uptimeValue >= 99;
              return (
                <div
                  key={i}
                  className={`flex-1 h-8 rounded ${isUp ? 'bg-green-500' : uptimeValue >= 95 ? 'bg-amber-500' : 'bg-red-500'}`}
                  title={`Day ${30 - i}: ${uptimeValue.toFixed(2)}% uptime`}
                />
              );
            })}
          </div>
          <div className="flex justify-between text-sm text-slate-500">
            <span>30 days ago</span>
            <span>Today</span>
          </div>
          <div className="mt-6 grid grid-cols-3 gap-4">
            <div className="text-center p-4 bg-slate-50 rounded-lg">
              <p className="text-2xl font-bold text-slate-900">{site.uptime_percent?.toFixed(2) || '100.00'}%</p>
              <p className="text-sm text-slate-500">Overall Uptime</p>
            </div>
            <div className="text-center p-4 bg-slate-50 rounded-lg">
              <p className="text-2xl font-bold text-slate-900">{uptime?.incidents || 0}</p>
              <p className="text-sm text-slate-500">Incidents</p>
            </div>
            <div className="text-center p-4 bg-slate-50 rounded-lg">
              <p className="text-2xl font-bold text-slate-900">{uptime?.avg_response || '-'}ms</p>
              <p className="text-sm text-slate-500">Avg Response</p>
            </div>
          </div>
        </Card>
      )}

      {activeTab === 'settings' && (
        <Card>
          <h3 className="text-lg font-semibold text-slate-900 mb-6">Site Settings</h3>
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Display Name</label>
              <input
                type="text"
                className="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-900"
                defaultValue={site.name}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Check Interval</label>
              <select className="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-900">
                <option value="5">Every 5 minutes</option>
                <option value="15">Every 15 minutes</option>
                <option value="30">Every 30 minutes</option>
                <option value="60">Every hour</option>
              </select>
            </div>
            <div className="pt-4">
              <Button>Save Settings</Button>
            </div>
          </div>
        </Card>
      )}
    </Layout>
  );
}
