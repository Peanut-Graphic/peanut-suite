import { useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  Tag,
  Link2,
  Users,
  MessageSquare,
} from 'lucide-react';
import { Layout } from '../components/layout';
import { Card, StatCard, SkeletonLoader as Skeleton, LineChart, BarChart, DoughnutChart } from '../components/common';
import { utmApi, linksApi, contactsApi, popupsApi, dashboardApi } from '../api/endpoints';

export default function Dashboard() {
  const [period, setPeriod] = useState<'7d' | '30d' | '90d'>('7d');

  const { data: utmStats, isLoading: loadingUTM } = useQuery({
    queryKey: ['utm-stats'],
    queryFn: () => utmApi.getAll({ per_page: 5 }),
  });

  const { data: linkStats, isLoading: loadingLinks } = useQuery({
    queryKey: ['link-stats'],
    queryFn: () => linksApi.getAll({ per_page: 1 }),
  });

  const { data: contactStats, isLoading: loadingContacts } = useQuery({
    queryKey: ['contact-stats'],
    queryFn: () => contactsApi.getAll({ per_page: 1 }),
  });

  const { data: popupStats, isLoading: loadingPopups } = useQuery({
    queryKey: ['popup-stats'],
    queryFn: () => popupsApi.getAll({ per_page: 1 }),
  });

  const { data: timeline } = useQuery({
    queryKey: ['dashboard-timeline', period],
    queryFn: () => dashboardApi.getTimeline(period),
  });

  const isLoading = loadingUTM || loadingLinks || loadingContacts || loadingPopups;

  // Generate chart data from timeline or use sample data
  const chartData = useMemo(() => {
    if (timeline && timeline.length > 0) {
      return {
        labels: timeline.map(d => new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
        utmClicks: timeline.map(d => d.utm_clicks),
        linkClicks: timeline.map(d => d.link_clicks),
        contacts: timeline.map(d => d.contacts),
        conversions: timeline.map(d => d.conversions),
      };
    }
    // Sample data for demo
    const days = period === '7d' ? 7 : period === '30d' ? 30 : 90;
    const labels = Array.from({ length: days }, (_, i) => {
      const date = new Date();
      date.setDate(date.getDate() - (days - 1 - i));
      return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    });
    return {
      labels: labels.filter((_, i) => i % (days > 30 ? 7 : days > 7 ? 3 : 1) === 0),
      utmClicks: Array.from({ length: Math.ceil(days / (days > 30 ? 7 : days > 7 ? 3 : 1)) }, () => Math.floor(Math.random() * 100) + 20),
      linkClicks: Array.from({ length: Math.ceil(days / (days > 30 ? 7 : days > 7 ? 3 : 1)) }, () => Math.floor(Math.random() * 80) + 10),
      contacts: Array.from({ length: Math.ceil(days / (days > 30 ? 7 : days > 7 ? 3 : 1)) }, () => Math.floor(Math.random() * 15) + 5),
      conversions: Array.from({ length: Math.ceil(days / (days > 30 ? 7 : days > 7 ? 3 : 1)) }, () => Math.floor(Math.random() * 10) + 2),
    };
  }, [timeline, period]);

  // Source breakdown for pie chart (sample data)
  const sourceData = useMemo(() => {
    if (utmStats?.data && utmStats.data.length > 0) {
      const sources: Record<string, number> = {};
      utmStats.data.forEach(utm => {
        sources[utm.utm_source] = (sources[utm.utm_source] || 0) + utm.click_count;
      });
      return {
        labels: Object.keys(sources),
        data: Object.values(sources),
      };
    }
    return {
      labels: ['Google', 'Facebook', 'Email', 'Direct'],
      data: [45, 25, 20, 10],
    };
  }, [utmStats]);

  return (
    <Layout title="Dashboard" description="Overview of your marketing suite">
      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        {isLoading ? (
          <>
            <Skeleton className="h-32" />
            <Skeleton className="h-32" />
            <Skeleton className="h-32" />
            <Skeleton className="h-32" />
          </>
        ) : (
          <>
            <StatCard
              title="UTM Codes"
              value={utmStats?.total || 0}
              icon={<Tag className="w-5 h-5" />}
              change={{ value: 12, type: 'increase' }}
            />
            <StatCard
              title="Short Links"
              value={linkStats?.total || 0}
              icon={<Link2 className="w-5 h-5" />}
              change={{ value: 8, type: 'increase' }}
            />
            <StatCard
              title="Contacts"
              value={contactStats?.total || 0}
              icon={<Users className="w-5 h-5" />}
              change={{ value: 24, type: 'increase' }}
            />
            <StatCard
              title="Popups"
              value={popupStats?.total || 0}
              icon={<MessageSquare className="w-5 h-5" />}
              change={{ value: 5, type: 'increase' }}
            />
          </>
        )}
      </div>

      {/* Quick Actions & Recent Activity */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Quick Actions */}
        <Card className="lg:col-span-1">
          <h3 className="text-lg font-semibold text-slate-900 mb-4">Quick Actions</h3>
          <div className="space-y-3">
            <QuickActionButton
              href="#/utm"
              icon={<Tag className="w-5 h-5" />}
              title="Create UTM Code"
              description="Generate tracked URLs"
            />
            <QuickActionButton
              href="#/links"
              icon={<Link2 className="w-5 h-5" />}
              title="Shorten Link"
              description="Create branded short links"
            />
            <QuickActionButton
              href="#/popups"
              icon={<MessageSquare className="w-5 h-5" />}
              title="Create Popup"
              description="Build conversion popups"
            />
          </div>
        </Card>

        {/* Recent UTM Codes */}
        <Card className="lg:col-span-2">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold text-slate-900">Recent UTM Codes</h3>
            <a
              href="#/utm/library"
              className="text-sm text-primary-600 hover:text-primary-700 font-medium"
            >
              View all
            </a>
          </div>
          {loadingUTM ? (
            <div className="space-y-3">
              <Skeleton className="h-12" />
              <Skeleton className="h-12" />
              <Skeleton className="h-12" />
            </div>
          ) : (
            <div className="text-sm text-slate-500">
              {utmStats?.data?.length ? (
                <ul className="divide-y divide-slate-100">
                  {utmStats.data.slice(0, 5).map((utm) => (
                    <li key={utm.id} className="py-3 flex items-center justify-between">
                      <div>
                        <p className="font-medium text-slate-900">{utm.utm_campaign}</p>
                        <p className="text-slate-500 text-xs truncate max-w-md">
                          {utm.base_url}
                        </p>
                      </div>
                      <div className="text-right">
                        <p className="text-slate-900">{utm.click_count} clicks</p>
                        <p className="text-xs text-slate-400">
                          {new Date(utm.created_at).toLocaleDateString()}
                        </p>
                      </div>
                    </li>
                  ))}
                </ul>
              ) : (
                <p>No UTM codes yet. Create your first one!</p>
              )}
            </div>
          )}
        </Card>
      </div>

      {/* Performance Overview */}
      <div className="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card className="lg:col-span-2">
          <div className="flex items-center justify-between mb-6">
            <h3 className="text-lg font-semibold text-slate-900">Performance Overview</h3>
            <select
              className="text-sm border border-slate-200 rounded-lg px-3 py-1.5 text-slate-900"
              value={period}
              onChange={(e) => setPeriod(e.target.value as '7d' | '30d' | '90d')}
            >
              <option value="7d">Last 7 days</option>
              <option value="30d">Last 30 days</option>
              <option value="90d">Last 90 days</option>
            </select>
          </div>
          <LineChart
            labels={chartData.labels}
            datasets={[
              { label: 'UTM Clicks', data: chartData.utmClicks, borderColor: '#6366f1' },
              { label: 'Link Clicks', data: chartData.linkClicks, borderColor: '#22c55e' },
            ]}
            height={280}
          />
        </Card>

        <Card>
          <h3 className="text-lg font-semibold text-slate-900 mb-6">Traffic Sources</h3>
          <DoughnutChart
            labels={sourceData.labels}
            data={sourceData.data}
            height={240}
          />
        </Card>
      </div>

      {/* Conversions Chart */}
      <div className="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <h3 className="text-lg font-semibold text-slate-900 mb-6">New Contacts</h3>
          <BarChart
            labels={chartData.labels}
            datasets={[
              { label: 'Contacts', data: chartData.contacts, backgroundColor: '#6366f1' },
            ]}
            height={200}
            showLegend={false}
          />
        </Card>

        <Card>
          <h3 className="text-lg font-semibold text-slate-900 mb-6">Popup Conversions</h3>
          <BarChart
            labels={chartData.labels}
            datasets={[
              { label: 'Conversions', data: chartData.conversions, backgroundColor: '#22c55e' },
            ]}
            height={200}
            showLegend={false}
          />
        </Card>
      </div>
    </Layout>
  );
}

interface QuickActionButtonProps {
  href: string;
  icon: React.ReactNode;
  title: string;
  description: string;
}

function QuickActionButton({ href, icon, title, description }: QuickActionButtonProps) {
  return (
    <a
      href={href}
      className="flex items-center gap-3 p-3 rounded-lg border border-slate-200 hover:border-primary-300 hover:bg-primary-50/50 transition-colors"
    >
      <div className="w-10 h-10 rounded-lg bg-primary-100 text-primary-600 flex items-center justify-center">
        {icon}
      </div>
      <div>
        <p className="font-medium text-slate-900">{title}</p>
        <p className="text-xs text-slate-500">{description}</p>
      </div>
    </a>
  );
}
