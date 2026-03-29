import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  BarChart2,
  Users,
  Eye,
  Target,
  TrendingUp,
  TrendingDown,
  Activity,
  Globe,
  Monitor,
  Smartphone,
  DollarSign,
  Calendar,
  RefreshCw,
} from 'lucide-react';
import { Layout } from '../components/layout';
import { Card, Select, Badge, Button } from '../components/common';
import { LineChart, BarChart, DoughnutChart } from '../components/common';
import { analyticsApi } from '../api/endpoints';
import type { AnalyticsBreakdownItem } from '../types';

const sourceColors: Record<string, string> = {
  'direct': '#6366f1',
  'google': '#22c55e',
  'facebook': '#3b82f6',
  'twitter': '#06b6d4',
  'linkedin': '#0077b5',
  'email': '#8b5cf6',
  'referral': '#f97316',
  'unknown': '#94a3b8',
};

const deviceColors: Record<string, string> = {
  'desktop': '#3b82f6',
  'mobile': '#10b981',
  'tablet': '#f59e0b',
  'unknown': '#94a3b8',
};

export default function Analytics() {
  const [period, setPeriod] = useState<'7d' | '30d' | '90d' | 'year'>('30d');

  // Fetch overview
  const { data: overview, isLoading: overviewLoading, refetch: refetchOverview } = useQuery({
    queryKey: ['analytics-overview', period],
    queryFn: () => analyticsApi.getOverview(period),
    refetchInterval: 5 * 60 * 1000,
  });

  // Fetch realtime
  const { data: realtime } = useQuery({
    queryKey: ['analytics-realtime'],
    queryFn: analyticsApi.getRealtime,
    refetchInterval: 5 * 60 * 1000,
  });

  // Fetch timeline
  const { data: timeline, isLoading: timelineLoading } = useQuery({
    queryKey: ['analytics-timeline', period],
    queryFn: () => analyticsApi.getTimeline(period),
  });

  // Fetch sources
  const { data: sources, isLoading: sourcesLoading } = useQuery({
    queryKey: ['analytics-sources', period],
    queryFn: () => analyticsApi.getSources(period),
  });

  // Fetch devices
  const { data: devices, isLoading: devicesLoading } = useQuery({
    queryKey: ['analytics-devices', period],
    queryFn: () => analyticsApi.getDevices(period),
  });

  // Fetch funnel
  const { data: funnel, isLoading: funnelLoading } = useQuery({
    queryKey: ['analytics-funnel', period],
    queryFn: () => analyticsApi.getFunnel(period),
  });

  const periodOptions = [
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: '90d', label: 'Last 90 days' },
    { value: 'year', label: 'Last year' },
  ];

  // Format timeline data for chart
  const timelineData = timeline?.timeline || [];
  const timelineLabels = timelineData.map((d) => {
    const date = new Date(d.date);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  });

  // Prepare source chart data
  const sourceData = sources?.sources || [];
  const sourceLabels = sourceData.map((s: AnalyticsBreakdownItem) => s.dimension_value || 'Unknown');
  const sourceCounts = sourceData.map((s: AnalyticsBreakdownItem) => s.total_count);
  const sourceChartColors = sourceLabels.map((label: string) =>
    sourceColors[label.toLowerCase()] || '#94a3b8'
  );

  // Prepare device chart data
  const deviceData = devices?.devices || [];
  const deviceLabels = deviceData.map((d: AnalyticsBreakdownItem) =>
    (d.dimension_value || 'Unknown').charAt(0).toUpperCase() + (d.dimension_value || 'unknown').slice(1)
  );
  const deviceCounts = deviceData.map((d: AnalyticsBreakdownItem) => d.total_count);
  const deviceChartColors = deviceLabels.map((label: string) =>
    deviceColors[label.toLowerCase()] || '#94a3b8'
  );

  // Funnel data
  const funnelData = funnel?.funnel || [];

  const formatNumber = (num: number | undefined) => {
    if (num === undefined) return '0';
    if (num >= 1000000) return `${(num / 1000000).toFixed(1)}M`;
    if (num >= 1000) return `${(num / 1000).toFixed(1)}K`;
    return num.toString();
  };

  const TrendIcon = ({ trend }: { trend: 'up' | 'down' }) =>
    trend === 'up'
      ? <TrendingUp className="w-4 h-4 text-green-500" />
      : <TrendingDown className="w-4 h-4 text-red-500" />;

  return (
    <Layout
      title="Analytics Dashboard"
      description="Unified analytics with aggregated stats and visualizations"
    >
      {/* Realtime Stats */}
      <div className="bg-gradient-to-r from-primary-600 to-primary-700 rounded-lg p-4 mb-6">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">
            <Activity className="w-5 h-5 text-white/80" />
            <span className="text-white/80 font-medium">Real-time</span>
            <span className="relative flex h-2 w-2">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
              <span className="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
            </span>
          </div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => refetchOverview()}
            className="text-white/80 hover:text-white hover:bg-white/10"
          >
            <RefreshCw className="w-4 h-4" />
          </Button>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
          <div>
            <div className="text-white/60 text-sm">Active Visitors</div>
            <div className="text-white text-2xl font-bold">{realtime?.active_visitors ?? 0}</div>
          </div>
          <div>
            <div className="text-white/60 text-sm">Recent Events</div>
            <div className="text-white text-2xl font-bold">{realtime?.recent_events ?? 0}</div>
          </div>
          <div>
            <div className="text-white/60 text-sm">Today's Pageviews</div>
            <div className="text-white text-2xl font-bold">{formatNumber(realtime?.today_pageviews)}</div>
          </div>
          <div>
            <div className="text-white/60 text-sm">Today's Conversions</div>
            <div className="text-white text-2xl font-bold">{realtime?.today_conversions ?? 0}</div>
          </div>
          <div>
            <div className="text-white/60 text-sm">Today's Revenue</div>
            <div className="text-white text-2xl font-bold">${(realtime?.today_revenue ?? 0).toLocaleString()}</div>
          </div>
        </div>
      </div>

      {/* Period Filter */}
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-2">
          <Calendar className="w-4 h-4 text-slate-400" />
          <span className="text-sm text-slate-500">Period:</span>
          <Select
            value={period}
            onChange={(e) => setPeriod(e.target.value as '7d' | '30d' | '90d' | 'year')}
            options={periodOptions}
            className="w-36"
          />
        </div>
        {overview && (
          <span className="text-sm text-slate-500">
            {overview.period.from} - {overview.period.to}
          </span>
        )}
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <Card className="p-4">
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2">
              <Users className="w-4 h-4 text-blue-500" />
              <span className="text-sm text-slate-500">Visitors</span>
            </div>
            {overview?.summary.visitors.change !== undefined && overview.summary.visitors.change !== 0 && (
              <TrendIcon trend={overview.summary.visitors.trend} />
            )}
          </div>
          <div className="text-2xl font-bold">{formatNumber(overview?.summary.visitors.total)}</div>
          {overview?.summary.visitors.change !== undefined && overview.summary.visitors.change !== 0 && (
            <div className={`text-sm ${overview.summary.visitors.trend === 'up' ? 'text-green-600' : 'text-red-600'}`}>
              {overview.summary.visitors.change > 0 ? '+' : ''}{overview.summary.visitors.change}% vs prev period
            </div>
          )}
        </Card>

        <Card className="p-4">
          <div className="flex items-center gap-2 mb-2">
            <Eye className="w-4 h-4 text-purple-500" />
            <span className="text-sm text-slate-500">Pageviews</span>
          </div>
          <div className="text-2xl font-bold">{formatNumber(overview?.summary.pageviews.total)}</div>
        </Card>

        <Card className="p-4">
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2">
              <Target className="w-4 h-4 text-green-500" />
              <span className="text-sm text-slate-500">Conversions</span>
            </div>
            {overview?.summary.conversions.change !== undefined && overview.summary.conversions.change !== 0 && (
              <TrendIcon trend={overview.summary.conversions.trend} />
            )}
          </div>
          <div className="text-2xl font-bold">{formatNumber(overview?.summary.conversions.total)}</div>
          {overview?.summary.conversions.change !== undefined && overview.summary.conversions.change !== 0 && (
            <div className={`text-sm ${overview.summary.conversions.trend === 'up' ? 'text-green-600' : 'text-red-600'}`}>
              {overview.summary.conversions.change > 0 ? '+' : ''}{overview.summary.conversions.change}% vs prev period
            </div>
          )}
        </Card>

        <Card className="p-4">
          <div className="flex items-center gap-2 mb-2">
            <DollarSign className="w-4 h-4 text-amber-500" />
            <span className="text-sm text-slate-500">Conversion Value</span>
          </div>
          <div className="text-2xl font-bold text-green-600">
            ${(overview?.summary.conversions.value ?? 0).toLocaleString()}
          </div>
        </Card>
      </div>

      {/* Timeline Chart */}
      <Card className="p-6 mb-6">
        <h3 className="text-lg font-semibold mb-4">Traffic Timeline</h3>
        {timelineLoading || overviewLoading ? (
          <div className="h-72 flex items-center justify-center text-slate-400">
            Loading...
          </div>
        ) : timelineData.length > 0 ? (
          <div className="h-72">
            <LineChart
              labels={timelineLabels}
              datasets={[
                {
                  label: 'Visitors',
                  data: timelineData.map((d) => d.visitors),
                  borderColor: '#3b82f6',
                  backgroundColor: 'rgba(59, 130, 246, 0.1)',
                  fill: true,
                },
                {
                  label: 'Pageviews',
                  data: timelineData.map((d) => d.pageviews),
                  borderColor: '#8b5cf6',
                  backgroundColor: 'rgba(139, 92, 246, 0.1)',
                  fill: true,
                },
                {
                  label: 'Conversions',
                  data: timelineData.map((d) => d.conversions),
                  borderColor: '#10b981',
                  backgroundColor: 'rgba(16, 185, 129, 0.1)',
                  fill: true,
                },
              ]}
            />
          </div>
        ) : (
          <div className="h-72 flex items-center justify-center text-slate-400">
            No data available
          </div>
        )}
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {/* Traffic Sources */}
        <Card className="p-6">
          <div className="flex items-center gap-2 mb-4">
            <Globe className="w-5 h-5 text-slate-400" />
            <h3 className="text-lg font-semibold">Traffic Sources</h3>
          </div>
          {sourcesLoading ? (
            <div className="h-64 flex items-center justify-center text-slate-400">
              Loading...
            </div>
          ) : sourceData.length > 0 ? (
            <>
              <div className="h-48">
                <DoughnutChart
                  labels={sourceLabels}
                  data={sourceCounts}
                  colors={sourceChartColors}
                />
              </div>
              <div className="mt-4 space-y-2">
                {sourceData.slice(0, 5).map((source: AnalyticsBreakdownItem, index: number) => (
                  <div key={index} className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <div
                        className="w-3 h-3 rounded-full"
                        style={{ backgroundColor: sourceChartColors[index] }}
                      />
                      <span className="text-sm">{source.dimension_value || 'Unknown'}</span>
                    </div>
                    <Badge variant="default">{source.total_count}</Badge>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <div className="h-64 flex items-center justify-center text-slate-400">
              No source data available
            </div>
          )}
        </Card>

        {/* Devices */}
        <Card className="p-6">
          <div className="flex items-center gap-2 mb-4">
            <Monitor className="w-5 h-5 text-slate-400" />
            <h3 className="text-lg font-semibold">Devices</h3>
          </div>
          {devicesLoading ? (
            <div className="h-64 flex items-center justify-center text-slate-400">
              Loading...
            </div>
          ) : deviceData.length > 0 ? (
            <>
              <div className="h-48">
                <BarChart
                  labels={deviceLabels}
                  datasets={[{
                    label: 'Visitors',
                    data: deviceCounts,
                    backgroundColor: deviceChartColors,
                  }]}
                />
              </div>
              <div className="mt-4 space-y-2">
                {deviceData.map((device: AnalyticsBreakdownItem, index: number) => (
                  <div key={index} className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      {device.dimension_value === 'desktop' ? (
                        <Monitor className="w-4 h-4 text-slate-400" />
                      ) : device.dimension_value === 'mobile' ? (
                        <Smartphone className="w-4 h-4 text-slate-400" />
                      ) : (
                        <BarChart2 className="w-4 h-4 text-slate-400" />
                      )}
                      <span className="text-sm capitalize">{device.dimension_value || 'Unknown'}</span>
                    </div>
                    <Badge variant="default">{device.total_count}</Badge>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <div className="h-64 flex items-center justify-center text-slate-400">
              No device data available
            </div>
          )}
        </Card>
      </div>

      {/* Conversion Funnel */}
      <Card className="p-6">
        <div className="flex items-center gap-2 mb-4">
          <Target className="w-5 h-5 text-slate-400" />
          <h3 className="text-lg font-semibold">Conversion Funnel</h3>
        </div>
        {funnelLoading ? (
          <div className="h-32 flex items-center justify-center text-slate-400">
            Loading...
          </div>
        ) : funnelData.length > 0 ? (
          <div className="flex items-center justify-center gap-4">
            {funnelData.map((stage, index) => (
              <div key={stage.stage} className="flex items-center gap-4">
                <div className="text-center">
                  <div
                    className="w-24 h-24 rounded-full flex items-center justify-center mb-2"
                    style={{
                      backgroundColor: index === 0 ? '#3b82f6' : index === 1 ? '#8b5cf6' : '#10b981',
                      opacity: 0.1 + (stage.rate / 100) * 0.9,
                    }}
                  >
                    <div className="text-center">
                      <div className="text-xl font-bold">{formatNumber(stage.count)}</div>
                      <div className="text-sm text-slate-500">{stage.rate}%</div>
                    </div>
                  </div>
                  <div className="text-sm font-medium">{stage.stage}</div>
                </div>
                {index < funnelData.length - 1 && (
                  <div className="text-slate-300">→</div>
                )}
              </div>
            ))}
          </div>
        ) : (
          <div className="h-32 flex items-center justify-center text-slate-400">
            No funnel data available
          </div>
        )}
      </Card>
    </Layout>
  );
}
