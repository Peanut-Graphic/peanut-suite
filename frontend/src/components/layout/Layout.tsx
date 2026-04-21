import { type ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import { clsx } from 'clsx';
import {
  LayoutDashboard,
  Link2,
  Tag,
  Users,
  Webhook,
  Eye,
  GitBranch,
  BarChart2,
  MessageSquare,
  Activity,
  Settings,
  Crown,
  UsersRound,
  Key,
  FileText,
} from 'lucide-react';

interface LayoutProps {
  children: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
}

// Get license data from WordPress
declare global {
  interface Window {
    peanutData?: {
      license?: {
        tier: 'free' | 'pro' | 'agency';
        isPro: boolean;
      };
      version?: string;
    };
  }
}

const getLicenseTier = () => window.peanutData?.license?.tier || 'free';
const hasTier = (required: 'free' | 'pro' | 'agency') => {
  const tierLevels = { free: 0, pro: 1, agency: 2 };
  return tierLevels[getLicenseTier()] >= tierLevels[required];
};

interface NavItem {
  name: string;
  href: string;
  icon: typeof LayoutDashboard;
  tier?: 'free' | 'pro' | 'agency';
}

const navigation: NavItem[] = [
  { name: 'Dashboard', href: '/', icon: LayoutDashboard },
  { name: 'UTM', href: '/utm', icon: Tag },
  { name: 'Links', href: '/links', icon: Link2 },
  { name: 'Contacts', href: '/contacts', icon: Users },
  { name: 'Webhooks', href: '/webhooks', icon: Webhook },
  { name: 'Visitors', href: '/visitors', icon: Eye, tier: 'pro' },
  { name: 'Attribution', href: '/attribution', icon: GitBranch, tier: 'pro' },
  { name: 'Analytics', href: '/analytics', icon: BarChart2, tier: 'pro' },
  { name: 'Popups', href: '/popups', icon: MessageSquare, tier: 'pro' },
  { name: 'Monitor', href: '/monitor', icon: Activity, tier: 'agency' },
  { name: 'Team', href: '/team', icon: UsersRound },
  { name: 'API Keys', href: '/api-keys', icon: Key },
  { name: 'Audit', href: '/audit-log', icon: FileText },
  { name: 'Settings', href: '/settings', icon: Settings },
];

export default function Layout({ children, title, description, action }: LayoutProps) {
  return (
    <div className="min-h-[100dvh] bg-slate-50">
      {/* Top Navigation */}
      <header className="bg-white border-b border-slate-200">
        <div className="px-4 py-4 sm:px-6">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
              {description && (
                <p className="text-sm text-slate-500 mt-0.5">{description}</p>
              )}
            </div>
            {action && <div className="sm:flex-shrink-0">{action}</div>}
          </div>
        </div>
        {/* Tab Navigation */}
        <nav className="px-3 sm:px-6 flex gap-1 overflow-x-auto pb-1 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
          {navigation.map((item) => {
            const hasAccess = !item.tier || hasTier(item.tier);
            const isLocked = item.tier && !hasAccess;

            if (isLocked) {
              return (
                <span
                  key={item.name}
                  className="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap border-transparent text-slate-400 cursor-not-allowed"
                  title={`${item.tier?.charAt(0).toUpperCase()}${item.tier?.slice(1)} feature`}
                >
                  <item.icon className="w-4 h-4" />
                  {item.name}
                  <Crown className="w-3 h-3 text-amber-500" />
                </span>
              );
            }

            return (
              <NavLink
                key={item.name}
                to={item.href}
                className={({ isActive }) =>
                  clsx(
                    'flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors',
                    isActive
                      ? 'border-primary-600 text-primary-600'
                      : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300'
                  )
                }
              >
                <item.icon className="w-4 h-4" />
                {item.name}
              </NavLink>
            );
          })}
        </nav>
      </header>

      {/* Main Content */}
      <main id="main-content" tabIndex={-1} className="p-4 sm:p-6 overflow-x-hidden">
        {children}
      </main>
    </div>
  );
}
