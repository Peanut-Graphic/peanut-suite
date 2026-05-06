import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Settings as SettingsIcon,
  Key,
  Link2,
  Bell,
  Database,
  Shield,
  ExternalLink,
  Check,
  AlertCircle,
} from 'lucide-react';
import { Layout } from '../components/layout';
import { Card, Button, Input, Badge, ThemeToggle } from '../components/common';
import { settingsApi } from '../api';
import type { License } from '../types';

type Tab = 'general' | 'license' | 'integrations' | 'notifications' | 'advanced';

export default function Settings() {
  const [activeTab, setActiveTab] = useState<Tab>('general');

  const tabs = [
    { id: 'general' as Tab, label: 'General', icon: SettingsIcon },
    { id: 'license' as Tab, label: 'License', icon: Key },
    { id: 'integrations' as Tab, label: 'Integrations', icon: Link2 },
    { id: 'notifications' as Tab, label: 'Notifications', icon: Bell },
    { id: 'advanced' as Tab, label: 'Advanced', icon: Database },
  ];

  return (
    <Layout title="Settings" description="Configure your Peanut Suite">
      <div className="flex gap-6">
        {/* Sidebar */}
        <div className="w-56 flex-shrink-0">
          <nav className="space-y-1">
            {tabs.map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={`w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors ${
                  activeTab === tab.id
                    ? 'bg-primary-50 text-primary-700'
                    : 'text-slate-600 hover:bg-slate-50'
                }`}
              >
                <tab.icon className="w-5 h-5" />
                {tab.label}
              </button>
            ))}
          </nav>
        </div>

        {/* Content */}
        <div className="flex-1">
          {activeTab === 'general' && <GeneralSettings />}
          {activeTab === 'license' && <LicenseSettings />}
          {activeTab === 'integrations' && <IntegrationSettings />}
          {activeTab === 'notifications' && <NotificationSettings />}
          {activeTab === 'advanced' && <AdvancedSettings />}
        </div>
      </div>
    </Layout>
  );
}

function GeneralSettings() {
  const [settings, setSettings] = useState({
    site_name: '',
    default_domain: '',
    timezone: 'UTC',
  });

  return (
    <Card>
      <h3 className="text-lg font-semibold text-slate-900 dark:text-white mb-6">General Settings</h3>
      <div className="space-y-6">
        <Input
          label="Site Name"
          value={settings.site_name}
          onChange={(e) => setSettings({ ...settings, site_name: e.target.value })}
          placeholder="My Website"
        />
        <Input
          label="Default Short Link Domain"
          value={settings.default_domain}
          onChange={(e) => setSettings({ ...settings, default_domain: e.target.value })}
          placeholder="example.com"
          helper="Domain used for short links (requires DNS configuration)"
        />
        <div>
          <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">
            Timezone
          </label>
          <select
            className="w-full border border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white rounded-lg px-3 py-2 text-sm"
            value={settings.timezone}
            onChange={(e) => setSettings({ ...settings, timezone: e.target.value })}
          >
            <option value="UTC">UTC</option>
            <option value="America/New_York">Eastern Time</option>
            <option value="America/Chicago">Central Time</option>
            <option value="America/Denver">Mountain Time</option>
            <option value="America/Los_Angeles">Pacific Time</option>
          </select>
        </div>
        <ThemeToggle variant="dropdown" showLabel />
        <div className="pt-4 border-t border-slate-200 dark:border-slate-700">
          <Button>Save Changes</Button>
        </div>
      </div>
    </Card>
  );
}

function LicenseSettings() {
  const queryClient = useQueryClient();
  const [licenseKey, setLicenseKey] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const { data: license, isLoading } = useQuery<License>({
    queryKey: ['license'],
    queryFn: () => settingsApi.getLicense(),
  });

  useEffect(() => {
    if (license?.status === 'free' || license?.status === 'invalid') {
      setLicenseKey('');
    }
  }, [license?.status]);

  const activate = useMutation({
    mutationFn: (key: string) => settingsApi.activateLicense(key),
    onSuccess: () => {
      setError(null);
      setSuccess('License activated.');
      setLicenseKey('');
      queryClient.invalidateQueries({ queryKey: ['license'] });
    },
    onError: (err: Error) => {
      setSuccess(null);
      setError(err.message || 'License activation failed.');
    },
  });

  const deactivate = useMutation({
    mutationFn: () => settingsApi.deactivateLicense(),
    onSuccess: () => {
      setError(null);
      setSuccess('License deactivated.');
      queryClient.invalidateQueries({ queryKey: ['license'] });
    },
    onError: (err: Error) => {
      setSuccess(null);
      setError(err.message || 'License deactivation failed.');
    },
  });

  const status = license?.status ?? null;
  const isActive = status === 'active';
  const tier = license?.tier;
  const expiresAt = license?.expires_at ?? null;
  const expiryLabel = formatExpiry(expiresAt);

  return (
    <div className="space-y-6">
      <Card>
        <h3 className="text-lg font-semibold text-slate-900 mb-6">License Status</h3>

        {isLoading ? (
          <div className="text-sm text-slate-500">Loading…</div>
        ) : isActive ? (
          <div className="flex items-start gap-4 p-4 bg-green-50 rounded-lg border border-green-200">
            <div className="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
              <Check className="w-5 h-5 text-green-600" />
            </div>
            <div>
              <p className="font-medium text-green-800">License Active</p>
              <p className="text-sm text-green-600">
                Your {tierLabel(tier)} license is active. Auto-updates and gated features are unlocked.
              </p>
              <div className="flex items-center gap-4 mt-3">
                <Badge variant="success">{tierLabel(tier)} Tier</Badge>
                {expiryLabel && (
                  <span className="text-sm text-green-600">{expiryLabel}</span>
                )}
              </div>
            </div>
          </div>
        ) : status === 'expired' ? (
          <div className="flex items-start gap-4 p-4 bg-amber-50 rounded-lg border border-amber-200">
            <div className="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center">
              <AlertCircle className="w-5 h-5 text-amber-600" />
            </div>
            <div>
              <p className="font-medium text-amber-800">License Expired</p>
              <p className="text-sm text-amber-600">
                Renew your license to keep auto-updates and gated features.
              </p>
            </div>
          </div>
        ) : (
          <div className="flex items-start gap-4 p-4 bg-amber-50 rounded-lg border border-amber-200">
            <div className="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center">
              <AlertCircle className="w-5 h-5 text-amber-600" />
            </div>
            <div>
              <p className="font-medium text-amber-800">No Active License</p>
              <p className="text-sm text-amber-600">
                Enter your license key to unlock Pro/Agency features and auto-updates.
              </p>
            </div>
          </div>
        )}
      </Card>

      <Card>
        <h3 className="text-lg font-semibold text-slate-900 mb-6">
          {isActive ? 'Update License' : 'Activate License'}
        </h3>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            const trimmed = licenseKey.trim();
            if (trimmed) {
              activate.mutate(trimmed);
            }
          }}
        >
          <Input
            label="License Key"
            value={licenseKey}
            onChange={(e) => setLicenseKey(e.target.value)}
            placeholder="XXXX-XXXX-XXXX-XXXX"
          />
          {error && (
            <div className="text-sm text-red-600 bg-red-50 border border-red-200 rounded p-2">
              {error}
            </div>
          )}
          {success && (
            <div className="text-sm text-green-700 bg-green-50 border border-green-200 rounded p-2">
              {success}
            </div>
          )}
          <div className="flex items-center gap-3">
            <Button type="submit" disabled={!licenseKey.trim() || activate.isPending}>
              {activate.isPending ? 'Activating…' : isActive ? 'Update Key' : 'Activate License'}
            </Button>
            {isActive && (
              <Button
                type="button"
                variant="ghost"
                onClick={() => {
                  if (confirm('Deactivate this license on this site?')) {
                    deactivate.mutate();
                  }
                }}
                disabled={deactivate.isPending}
              >
                {deactivate.isPending ? 'Deactivating…' : 'Deactivate'}
              </Button>
            )}
          </div>
        </form>
      </Card>

      <Card>
        <h3 className="text-lg font-semibold text-slate-900 mb-4">Feature Access</h3>
        <div className="space-y-3">
          <FeatureRow feature="UTM Builder" included />
          <FeatureRow feature="Short Links" included />
          <FeatureRow feature="Contact Management" included />
          <FeatureRow feature="Popups & Forms" included={isActive} tier="Pro" />
          <FeatureRow feature="Analytics Dashboard" included={isActive} tier="Pro" />
          <FeatureRow
            feature="Multi-site Monitor"
            included={isActive && (tier === 'agency' || tier === 'enterprise')}
            tier="Agency"
          />
        </div>
      </Card>
    </div>
  );
}

function tierLabel(tier?: string): string {
  if (!tier) return 'Free';
  return tier.charAt(0).toUpperCase() + tier.slice(1);
}

function formatExpiry(expiresAt: string | null): string | null {
  if (!expiresAt) return 'No expiration';
  const date = new Date(expiresAt);
  if (Number.isNaN(date.getTime())) return null;
  return `Expires: ${date.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })}`;
}

function FeatureRow({ feature, included, tier }: { feature: string; included: boolean; tier?: string }) {
  return (
    <div className="flex items-center justify-between py-2 border-b border-slate-100 last:border-0">
      <span className="text-sm text-slate-700">{feature}</span>
      <div className="flex items-center gap-2">
        {tier && <Badge variant="default" size="sm">{tier}</Badge>}
        {included ? (
          <Check className="w-5 h-5 text-green-500" />
        ) : (
          <span className="w-5 h-5 rounded-full bg-slate-200" />
        )}
      </div>
    </div>
  );
}

function IntegrationSettings() {
  return (
    <div className="space-y-6">
      <Card>
        <h3 className="text-lg font-semibold text-slate-900 mb-6">Google Analytics</h3>
        <div className="flex items-start gap-4 p-4 bg-slate-50 rounded-lg border border-slate-200">
          <div className="w-10 h-10 bg-white rounded-lg border border-slate-200 flex items-center justify-center">
            <svg className="w-6 h-6" viewBox="0 0 24 24">
              <path
                fill="#F9AB00"
                d="M22.84 2.998c-.606-.606-1.417-.998-2.34-.998h-17c-.923 0-1.734.392-2.34.998-.606.606-.998 1.417-.998 2.34v13.324c0 .923.392 1.734.998 2.34.606.606 1.417.998 2.34.998h17c.923 0 1.734-.392 2.34-.998.606-.606.998-1.417.998-2.34V5.338c0-.923-.392-1.734-.998-2.34z"
              />
              <path
                fill="#E37400"
                d="M12 16.5c-2.49 0-4.5-2.01-4.5-4.5s2.01-4.5 4.5-4.5 4.5 2.01 4.5 4.5-2.01 4.5-4.5 4.5z"
              />
            </svg>
          </div>
          <div className="flex-1">
            <p className="font-medium text-slate-900">Connect Google Analytics 4</p>
            <p className="text-sm text-slate-500 mb-3">
              View UTM performance data directly in your dashboard.
            </p>
            <Button size="sm" icon={<ExternalLink className="w-4 h-4" />}>
              Connect GA4
            </Button>
          </div>
        </div>
      </Card>

      <Card>
        <h3 className="text-lg font-semibold text-slate-900 mb-6">Email Services</h3>
        <div className="space-y-4">
          <IntegrationCard
            name="Mailchimp"
            description="Sync contacts with Mailchimp lists"
            connected={false}
          />
          <IntegrationCard
            name="ConvertKit"
            description="Send contacts to ConvertKit sequences"
            connected={false}
          />
          <IntegrationCard
            name="ActiveCampaign"
            description="Integrate with ActiveCampaign automation"
            connected={false}
          />
        </div>
      </Card>
    </div>
  );
}

function IntegrationCard({
  name,
  description,
  connected,
}: {
  name: string;
  description: string;
  connected: boolean;
}) {
  return (
    <div className="flex items-center justify-between p-4 border border-slate-200 rounded-lg">
      <div>
        <p className="font-medium text-slate-900">{name}</p>
        <p className="text-sm text-slate-500">{description}</p>
      </div>
      {connected ? (
        <Badge variant="success">Connected</Badge>
      ) : (
        <Button variant="outline" size="sm">
          Connect
        </Button>
      )}
    </div>
  );
}

function NotificationSettings() {
  const [settings, setSettings] = useState({
    email_new_contact: true,
    email_popup_milestone: true,
    email_weekly_report: false,
  });

  return (
    <Card>
      <h3 className="text-lg font-semibold text-slate-900 mb-6">Email Notifications</h3>
      <div className="space-y-4">
        <NotificationToggle
          label="New Contact Captured"
          description="Get notified when a new contact is added"
          enabled={settings.email_new_contact}
          onChange={(v) => setSettings({ ...settings, email_new_contact: v })}
        />
        <NotificationToggle
          label="Popup Milestones"
          description="Get notified when popups reach view/conversion milestones"
          enabled={settings.email_popup_milestone}
          onChange={(v) => setSettings({ ...settings, email_popup_milestone: v })}
        />
        <NotificationToggle
          label="Weekly Report"
          description="Receive a weekly summary of your marketing metrics"
          enabled={settings.email_weekly_report}
          onChange={(v) => setSettings({ ...settings, email_weekly_report: v })}
        />
      </div>
      <div className="pt-6 mt-6 border-t border-slate-200">
        <Button>Save Preferences</Button>
      </div>
    </Card>
  );
}

function NotificationToggle({
  label,
  description,
  enabled,
  onChange,
}: {
  label: string;
  description: string;
  enabled: boolean;
  onChange: (value: boolean) => void;
}) {
  return (
    <div className="flex items-center justify-between py-3 border-b border-slate-100 last:border-0">
      <div>
        <p className="font-medium text-slate-900">{label}</p>
        <p className="text-sm text-slate-500">{description}</p>
      </div>
      <button
        onClick={() => onChange(!enabled)}
        className={`relative w-11 h-6 rounded-full transition-colors ${
          enabled ? 'bg-primary-600' : 'bg-slate-200'
        }`}
      >
        <span
          className={`absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform ${
            enabled ? 'translate-x-5' : 'translate-x-0'
          }`}
        />
      </button>
    </div>
  );
}

function AdvancedSettings() {
  return (
    <div className="space-y-6">
      <Card>
        <h3 className="text-lg font-semibold text-slate-900 mb-6">Data Management</h3>
        <div className="space-y-4">
          <div className="flex items-center justify-between p-4 border border-slate-200 rounded-lg">
            <div>
              <p className="font-medium text-slate-900">Export All Data</p>
              <p className="text-sm text-slate-500">
                Download all your UTMs, links, contacts, and popup data
              </p>
            </div>
            <Button variant="outline" size="sm">
              Export
            </Button>
          </div>
          <div className="flex items-center justify-between p-4 border border-slate-200 rounded-lg">
            <div>
              <p className="font-medium text-slate-900">Import Data</p>
              <p className="text-sm text-slate-500">
                Import data from CSV files
              </p>
            </div>
            <Button variant="outline" size="sm">
              Import
            </Button>
          </div>
        </div>
      </Card>

      <Card>
        <h3 className="text-lg font-semibold text-slate-900 mb-6">Cache & Performance</h3>
        <div className="space-y-4">
          <div className="flex items-center justify-between p-4 border border-slate-200 rounded-lg">
            <div>
              <p className="font-medium text-slate-900">Clear Cache</p>
              <p className="text-sm text-slate-500">
                Clear all cached data and analytics
              </p>
            </div>
            <Button variant="outline" size="sm">
              Clear Cache
            </Button>
          </div>
        </div>
      </Card>

      <Card className="border-red-200">
        <h3 className="text-lg font-semibold text-red-600 mb-6 flex items-center gap-2">
          <Shield className="w-5 h-5" />
          Danger Zone
        </h3>
        <div className="flex items-center justify-between p-4 bg-red-50 border border-red-200 rounded-lg">
          <div>
            <p className="font-medium text-red-800">Delete All Data</p>
            <p className="text-sm text-red-600">
              Permanently delete all data. This cannot be undone.
            </p>
          </div>
          <Button variant="danger" size="sm">
            Delete All
          </Button>
        </div>
      </Card>
    </div>
  );
}
