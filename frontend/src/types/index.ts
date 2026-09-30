// API Response types
export interface ApiResponse<T> {
  success: boolean;
  data?: T;
  message?: string;
  code?: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  total: number;
  total_pages: number;
  page: number;
  per_page: number;
}

// UTM Types
export interface UTM {
  id: number;
  base_url: string;
  utm_source: string;
  utm_medium: string;
  utm_campaign: string;
  utm_term?: string;
  utm_content?: string;
  full_url: string;
  program?: string;
  tags: string[];
  notes?: string;
  click_count: number;
  is_archived: boolean;
  created_at: string;
  updated_at: string;
}

export interface UTMFormData {
  base_url: string;
  utm_source: string;
  utm_medium: string;
  utm_campaign: string;
  utm_term?: string;
  utm_content?: string;
  program?: string;
  tags?: string[];
  notes?: string;
}

// Link Types
export interface Link {
  id: number;
  original_url: string;
  slug: string;
  short_url: string;
  title?: string;
  utm_id?: number;
  click_count: number;
  unique_clicks: number;
  status: 'active' | 'inactive' | 'expired';
  is_active: boolean;
  password_protected: boolean;
  expires_at?: string;
  created_at: string;
  updated_at: string;
}

export interface LinkClick {
  id: number;
  link_id: number;
  ip_hash: string;
  user_agent: string;
  referrer?: string;
  device: string;
  browser: string;
  os: string;
  country?: string;
  clicked_at: string;
}

export interface LinkFormData {
  original_url: string;
  slug?: string;
  title?: string;
  utm_id?: number;
  password?: string;
  expires_at?: string;
}

// Contact Types
export type ContactStatus = 'lead' | 'contacted' | 'qualified' | 'customer' | 'churned';

export interface Contact {
  id: number;
  email: string;
  first_name?: string;
  last_name?: string;
  phone?: string;
  company?: string;
  status: ContactStatus;
  source?: string;
  source_detail?: string;
  score: number;
  tags: string[];
  custom_fields: Record<string, unknown>;
  last_activity_at?: string;
  created_at: string;
  updated_at: string;
}

export interface ContactActivity {
  id: number;
  contact_id: number;
  type: string;
  description: string;
  created_at: string;
}

export interface ContactFormData {
  email: string;
  first_name?: string;
  last_name?: string;
  phone?: string;
  company?: string;
  status?: ContactStatus;
  source?: string;
  tags?: string[];
  custom_fields?: Record<string, unknown>;
}

// Popup Types
export type PopupType = 'modal' | 'slide-in' | 'bar' | 'fullscreen';
export type PopupStatus = 'draft' | 'active' | 'paused' | 'archived';
export type TriggerType = 'time_delay' | 'scroll_percent' | 'scroll_element' | 'exit_intent' | 'click' | 'page_views' | 'inactivity';

export interface PopupFormField {
  name: string;
  type: 'email' | 'text' | 'tel' | 'textarea' | 'select' | 'checkbox';
  label: string;
  placeholder?: string;
  required?: boolean;
  options?: { value: string; label: string }[];
}

export interface PopupTrigger {
  type: TriggerType;
  delay?: number;
  percent?: number;
  selector?: string;
  offset?: number;
  sensitivity?: number;
  count?: number;
  timeout?: number;
}

export interface PopupDisplayRules {
  pages?: 'all' | 'homepage' | { mode: 'include' | 'exclude'; ids?: number[]; types?: string[]; url_patterns?: string[] };
  devices?: ('desktop' | 'tablet' | 'mobile')[];
  user_status?: 'all' | 'logged_in' | 'logged_out' | { roles: string[] };
  referrer?: {
    mode?: 'include' | 'exclude';
    patterns?: string[];
    include_direct?: boolean;
    include_internal?: boolean;
  };
}

export interface PopupStyles {
  background_color?: string;
  text_color?: string;
  button_color?: string;
  button_text_color?: string;
  border_radius?: number;
  max_width?: number;
}

export interface PopupSettings {
  animation?: 'fade' | 'slide' | 'scale';
  overlay?: boolean;
  overlay_color?: string;
  close_button?: boolean;
  close_on_overlay?: boolean;
  close_on_esc?: boolean;
  hide_after_dismiss_days?: number;
  hide_after_convert_days?: number;
}

export interface Popup {
  id: number;
  name: string;
  type: PopupType;
  position: string;
  status: PopupStatus;
  priority: number;
  title?: string;
  content?: string;
  image_url?: string;
  form_fields: PopupFormField[];
  button_text: string;
  success_message: string;
  triggers: PopupTrigger;
  display_rules: PopupDisplayRules;
  styles: PopupStyles;
  settings: PopupSettings;
  views: number;
  conversions: number;
  conversion_rate: number;
  start_date?: string;
  end_date?: string;
  created_at: string;
  updated_at: string;
}

export interface PopupFormData {
  name: string;
  type?: PopupType;
  position?: string;
  status?: PopupStatus;
  priority?: number;
  title?: string;
  content?: string;
  image_url?: string;
  form_fields?: PopupFormField[];
  button_text?: string;
  success_message?: string;
  triggers?: PopupTrigger;
  display_rules?: PopupDisplayRules;
  styles?: PopupStyles;
  settings?: PopupSettings;
  start_date?: string;
  end_date?: string;
}

// Dashboard Types
export interface DashboardStats {
  utm_total: number;
  utm_created: number;
  utm_clicks: number;
  utm_top_campaigns: { utm_campaign: string; clicks: number; count: number }[];
  links_total: number;
  links_clicks: number;
  links_unique_clicks: number;
  contacts_total: number;
  contacts_new: number;
  contacts_by_status: Record<ContactStatus, number>;
  popups_active: number;
  popups_views: number;
  popups_conversions: number;
  popups_conversion_rate: number;
}

export interface TimelineData {
  date: string;
  utm_clicks: number;
  link_clicks: number;
  contacts: number;
  conversions: number;
}

export interface ActivityItem {
  id: string;
  type: 'utm' | 'link' | 'contact' | 'popup';
  action: string;
  description: string;
  timestamp: string;
}

// Settings Types
export interface Settings {
  link_prefix: string;
  track_clicks: boolean;
  anonymize_ip: boolean;
}

export interface Module {
  id: string;
  name: string;
  description: string;
  icon: string;
  active: boolean;
  pro: boolean;
  tier?: string;
  locked: boolean;
  required_tier: string;
}

export interface License {
  status: 'active' | 'expired' | 'invalid' | 'free';
  tier: 'free' | 'pro' | 'agency' | 'enterprise';
  expires_at?: string;
  features: Record<string, number | boolean>;
}

// Tag Types
export interface Tag {
  id: number;
  name: string;
  color: string;
}

// Monitor Types (Agency)
export interface MonitorSite {
  id: number;
  url: string;
  name?: string;
  status: 'connected' | 'disconnected' | 'error';
  health_score: number;
  uptime_percent: number;
  wp_version?: string;
  php_version?: string;
  ssl_enabled: boolean;
  updates_available: number;
  last_checked?: string;
  created_at: string;
  updated_at: string;
}

export interface MonitorHealth {
  site_id: number;
  wordpress_version: string;
  php_version: string;
  mysql_version: string;
  ssl_enabled: boolean;
  multisite: boolean;
  debug_mode: boolean;
  memory_limit: string;
  max_execution_time: number;
  disk_total: string;
  disk_used: string;
  disk_free: string;
  disk_percent: number;
  plugins_active: number;
  plugins_inactive: number;
  plugins_updates: number;
  plugin_updates?: { slug: string; name: string; current: string; new: string }[];
  theme_updates?: { slug: string; name: string; current: string; new: string }[];
  last_backup?: string;
  checked_at: string;
}

// Webhook Types
export type WebhookStatus = 'pending' | 'processing' | 'processed' | 'failed';

export interface Webhook {
  id: number;
  source: string;
  event: string;
  payload: Record<string, unknown>;
  signature: string | null;
  ip_address: string | null;
  processed_at: string | null;
  status: WebhookStatus;
  error_message: string | null;
  retry_count: number;
  created_at: string;
}

export interface WebhookStats {
  total: number;
  pending: number;
  processing: number;
  processed: number;
  failed: number;
  today: number;
}

export interface WebhookFilters {
  sources: string[];
  events: string[];
  statuses: WebhookStatus[];
}

/** Per-source signing status. Never carries a secret. */
export interface WebhookSigningSource {
  source: string;
  /** A signing secret is configured: unsigned webhooks are rejected. */
  signed: boolean;
  /** False when a configured secret cannot be decrypted (everything is rejected). */
  readable: boolean;
  /** This source has delivered at least one webhook. */
  seen: boolean;
}

export interface WebhookSigningStatus {
  endpoint_url: string;
  sources: WebhookSigningSource[];
  unsigned_seen_sources: string[];
}

export interface WebhookSigningSecretResult {
  source: string;
  signed: boolean;
  /** Present only in the response to a Generate request; shown once. */
  secret?: string;
}

// Visitor Types
export interface Visitor {
  id: number;
  visitor_id: string;
  first_seen: string;
  last_seen: string;
  total_visits: number;
  total_pageviews: number;
  device_type: 'desktop' | 'mobile' | 'tablet' | null;
  browser: string | null;
  os: string | null;
  country: string | null;
  region: string | null;
  city: string | null;
  customer_id: number | null;
  contact_id: number | null;
  email: string | null;
  created_at: string;
  recent_events?: VisitorEvent[];
}

export interface VisitorEvent {
  id: number;
  visitor_id: string;
  session_id: string | null;
  event_type: string;
  page_url: string | null;
  page_title: string | null;
  referrer: string | null;
  utm_source: string | null;
  utm_medium: string | null;
  utm_campaign: string | null;
  utm_term: string | null;
  utm_content: string | null;
  custom_data: Record<string, unknown> | null;
  created_at: string;
}

export interface VisitorStats {
  total: number;
  identified: number;
  anonymous: number;
  today: number;
  this_week: number;
  total_pageviews: number;
  events_today: number;
}

// Attribution Types
export interface Touch {
  id: number;
  visitor_id: string;
  session_id: string | null;
  touch_type: string;
  channel: string | null;
  source: string | null;
  medium: string | null;
  campaign: string | null;
  content: string | null;
  term: string | null;
  landing_page: string | null;
  referrer: string | null;
  touch_time: string;
  conversion_id: number | null;
  created_at: string;
}

export interface Conversion {
  id: number;
  visitor_id: string;
  conversion_type: string;
  conversion_value: number;
  source: string | null;
  source_id: string | null;
  customer_email: string | null;
  customer_name: string | null;
  metadata: Record<string, unknown> | null;
  converted_at: string;
  created_at: string;
  touches?: Touch[];
  attribution?: AttributionResult[];
}

export interface AttributionResult {
  id: number;
  conversion_id: number;
  touch_id: number;
  model: string;
  credit: number;
  calculated_at: string;
  channel?: string;
  source?: string;
  medium?: string;
  campaign?: string;
  touch_time?: string;
}

export interface AttributionModel {
  id: string;
  name: string;
  description: string;
}

export interface ChannelPerformance {
  channel: string;
  conversions: number;
  attributed_credit: number;
  attributed_value: number;
  touches: number;
}

export interface AttributionStats {
  total_conversions: number;
  total_value: number;
  today_conversions: number;
  today_value: number;
  month_conversions: number;
  month_value: number;
  total_touches: number;
}

export interface AttributionReport {
  model: string;
  model_name: string;
  model_description: string;
  date_range: {
    from: string;
    to: string;
  };
  channels: ChannelPerformance[];
  stats: AttributionStats;
}

// Analytics Types
export interface AnalyticsOverview {
  period: {
    from: string;
    to: string;
  };
  realtime: AnalyticsRealtime;
  summary: {
    visitors: {
      total: number;
      change: number;
      trend: 'up' | 'down';
    };
    pageviews: {
      total: number;
    };
    conversions: {
      total: number;
      value: number;
      change: number;
      trend: 'up' | 'down';
    };
  };
  breakdown: Record<string, Record<string, { count: number; value: number }>>;
}

export interface AnalyticsRealtime {
  active_visitors?: number;
  recent_events?: number;
  today_pageviews?: number;
  today_conversions?: number;
  today_revenue?: number;
}

export interface AnalyticsTimeline {
  period: {
    from: string;
    to: string;
  };
  timeline: AnalyticsTimelinePoint[];
}

export interface AnalyticsTimelinePoint {
  date: string;
  visitors: number;
  pageviews: number;
  conversions: number;
}

export interface AnalyticsSources {
  period: {
    from: string;
    to: string;
  };
  sources: AnalyticsBreakdownItem[];
  channels: AnalyticsBreakdownItem[];
}

export interface AnalyticsDevices {
  period: {
    from: string;
    to: string;
  };
  devices: AnalyticsBreakdownItem[];
  browsers: AnalyticsBreakdownItem[];
}

export interface AnalyticsBreakdownItem {
  dimension_value: string;
  total_count: number;
  total_value: number;
}

export interface AnalyticsFunnel {
  period: {
    from: string;
    to: string;
  };
  funnel: {
    stage: string;
    count: number;
    rate: number;
  }[];
}

export interface AnalyticsComparison {
  current_period: {
    from: string;
    to: string;
  };
  previous_period: {
    from: string;
    to: string;
  };
  comparisons: {
    visitors: AnalyticsComparisonMetric;
    pageviews: AnalyticsComparisonMetric;
    conversions: AnalyticsComparisonMetric;
  };
}

export interface AnalyticsComparisonMetric {
  current: number;
  previous: number;
  change: number;
  change_percent: number;
  trend: 'up' | 'down';
}

// Account Types (Multi-tenancy)
export type AccountStatus = 'active' | 'suspended' | 'cancelled';
export type AccountTier = 'free' | 'pro' | 'agency' | 'enterprise';
export type MemberRole = 'owner' | 'admin' | 'member' | 'viewer';
export type ApiKeyScope =
  | 'links:read'
  | 'links:write'
  | 'utms:read'
  | 'utms:write'
  | 'contacts:read'
  | 'contacts:write'
  | 'analytics:read';

// Feature Permission Types
export type FeatureKey =
  | 'utm'
  | 'links'
  | 'contacts'
  | 'webhooks'
  | 'visitors'
  | 'attribution'
  | 'analytics'
  | 'popups'
  | 'monitor';

export interface FeaturePermission {
  access: boolean;
}

export type FeaturePermissions = Record<FeatureKey, FeaturePermission>;

export interface FeatureConfig {
  name: string;
  tier: 'free' | 'pro' | 'agency';
  available: boolean;
}

export type AvailableFeatures = Record<FeatureKey, FeatureConfig>;

// UTM Access Types
export type UTMAccessLevel = 'view' | 'edit' | 'full';

export interface UTMAccess {
  utm_id: number;
  user_id: number;
  user_email: string;
  user_name: string;
  access_level: UTMAccessLevel;
  assigned_by: number;
  assigned_at: string;
}

export interface Account {
  id: number;
  name: string;
  slug: string;
  status: AccountStatus;
  tier: AccountTier;
  max_users: number;
  owner_user_id: number;
  role?: MemberRole;
  settings?: Record<string, unknown>;
  created_at: string;
  updated_at: string;
}

export interface AccountStats {
  member_count: number;
  active_api_keys: number;
}

export interface AccountMember {
  user_id: number;
  user_login: string;
  user_email: string;
  display_name: string;
  role: MemberRole;
  feature_permissions?: FeaturePermissions;
  invited_at?: string;
  accepted_at?: string;
  created_at: string;
}

export interface ApiKey {
  id: number;
  account_id: number;
  key_id: string;
  key_preview: string;
  name: string;
  scopes: ApiKeyScope[];
  created_by: number;
  created_by_name?: string;
  last_used_at?: string;
  last_used_ip?: string;
  expires_at?: string;
  revoked_at?: string;
  revoked_by?: number;
  created_at: string;
}

export interface ApiKeyWithSecret {
  id: number;
  key_id: string;
  key: string; // The full key (only shown once)
  name: string;
  scopes: ApiKeyScope[];
  expires_at?: string;
  created_at: string;
}

export interface ApiKeyFormData {
  name: string;
  scopes: ApiKeyScope[];
  expires_at?: string;
}

export interface ApiKeyStats {
  total: number;
  active: number;
  revoked: number;
  used_last_30_days: number;
}

export type AuditAction = 'create' | 'update' | 'delete' | 'view' | 'login' | 'logout' | 'export' | 'import' | 'invite' | 'revoke' | 'api_call';
export type AuditResourceType = 'account' | 'member' | 'api_key' | 'utm' | 'link' | 'contact' | 'tag' | 'popup' | 'webhook' | 'settings' | 'audit_log';

export interface AuditLogEntry {
  id: number;
  account_id: number;
  action: AuditAction;
  resource_type: AuditResourceType;
  resource_id?: number;
  user_id?: number;
  user_name?: string;
  user_email?: string;
  api_key_id?: number;
  ip_address?: string;
  user_agent?: string;
  details?: Record<string, unknown>;
  created_at: string;
}

export interface AuditLogFilters {
  action?: AuditAction;
  resource_type?: AuditResourceType;
  user_id?: number;
  date_from?: string;
  date_to?: string;
}
