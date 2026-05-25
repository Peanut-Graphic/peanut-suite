import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Save,
  Eye,
  ArrowLeft,
  Paintbrush,
  MousePointer,
  Target,
  Layout,
} from 'lucide-react';
import { Layout as PageLayout } from '../components/layout';
import { Card, Button, Input, Select, Textarea } from '../components/common';
import { popupsApi } from '../api/endpoints';

interface LocalTrigger {
  type: string;
  value: number | string;
}

type Tab = 'content' | 'trigger' | 'targeting' | 'style';

const popupTypes = [
  { value: 'modal', label: 'Modal' },
  { value: 'slide-in', label: 'Slide In' },
  { value: 'bar', label: 'Top/Bottom Bar' },
  { value: 'fullscreen', label: 'Fullscreen' },
];

const triggerTypes = [
  { value: 'time_delay', label: 'Time Delay' },
  { value: 'scroll_percent', label: 'Scroll Percentage' },
  { value: 'scroll_element', label: 'Scroll to Element' },
  { value: 'exit_intent', label: 'Exit Intent' },
  { value: 'click', label: 'Click Element' },
  { value: 'page_views', label: 'Page Views' },
  { value: 'inactivity', label: 'User Inactivity' },
];

const positionOptions = [
  { value: 'center', label: 'Center' },
  { value: 'top-left', label: 'Top Left' },
  { value: 'top-right', label: 'Top Right' },
  { value: 'bottom-left', label: 'Bottom Left' },
  { value: 'bottom-right', label: 'Bottom Right' },
];

interface PopupFormData {
  name: string;
  type: string;
  status: string;
  content: {
    title: string;
    body: string;
    cta_text: string;
    cta_url: string;
    image_url: string;
  };
  trigger: LocalTrigger;
  targeting: {
    pages: string[];
    exclude_pages: string[];
    devices: string[];
    show_to: string;
  };
  style: {
    position: string;
    background_color: string;
    text_color: string;
    button_color: string;
    button_text_color: string;
    overlay_color: string;
    overlay_opacity: number;
    border_radius: number;
    width: string;
  };
  settings: {
    show_close_button: boolean;
    close_on_overlay: boolean;
    cookie_duration: number;
    max_displays: number;
  };
}

const defaultFormData: PopupFormData = {
  name: '',
  type: 'modal',
  status: 'draft',
  content: {
    title: 'Welcome!',
    body: 'Subscribe to our newsletter for updates.',
    cta_text: 'Subscribe',
    cta_url: '',
    image_url: '',
  },
  trigger: {
    type: 'time_delay',
    value: 5,
  },
  targeting: {
    pages: [],
    exclude_pages: [],
    devices: ['desktop', 'tablet', 'mobile'],
    show_to: 'all',
  },
  style: {
    position: 'center',
    background_color: '#ffffff',
    text_color: '#1e293b',
    button_color: '#6366f1',
    button_text_color: '#ffffff',
    overlay_color: '#000000',
    overlay_opacity: 50,
    border_radius: 12,
    width: '480px',
  },
  settings: {
    show_close_button: true,
    close_on_overlay: true,
    cookie_duration: 7,
    max_displays: 1,
  },
};

export default function PopupBuilder() {
  const { id } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const isEditing = !!id;

  const [activeTab, setActiveTab] = useState<Tab>('content');
  const [formData, setFormData] = useState<PopupFormData>(defaultFormData);

  const { data: popup, isLoading } = useQuery({
    queryKey: ['popup', id],
    queryFn: () => popupsApi.getById(Number(id)),
    enabled: isEditing,
  });

  useEffect(() => {
    if (popup) {
      // Map from actual Popup type to local form data structure
      const trigger = popup.triggers || defaultFormData.trigger;
      const triggerValue = trigger.delay ?? trigger.percent ?? trigger.count ?? trigger.timeout ?? trigger.selector ?? 5;

      setFormData({
        name: popup.name,
        type: popup.type,
        status: popup.status,
        content: {
          title: popup.title || defaultFormData.content.title,
          body: popup.content || defaultFormData.content.body,
          cta_text: popup.button_text || defaultFormData.content.cta_text,
          cta_url: '',
          image_url: popup.image_url || '',
        },
        trigger: {
          type: trigger.type || 'time_delay',
          value: triggerValue,
        },
        targeting: defaultFormData.targeting,
        style: {
          position: popup.position || defaultFormData.style.position,
          background_color: popup.styles?.background_color || defaultFormData.style.background_color,
          text_color: popup.styles?.text_color || defaultFormData.style.text_color,
          button_color: popup.styles?.button_color || defaultFormData.style.button_color,
          button_text_color: popup.styles?.button_text_color || defaultFormData.style.button_text_color,
          overlay_color: popup.settings?.overlay_color || defaultFormData.style.overlay_color,
          overlay_opacity: defaultFormData.style.overlay_opacity,
          border_radius: popup.styles?.border_radius || defaultFormData.style.border_radius,
          width: popup.styles?.max_width ? `${popup.styles.max_width}px` : defaultFormData.style.width,
        },
        settings: {
          show_close_button: popup.settings?.close_button ?? defaultFormData.settings.show_close_button,
          close_on_overlay: popup.settings?.close_on_overlay ?? defaultFormData.settings.close_on_overlay,
          cookie_duration: popup.settings?.hide_after_dismiss_days ?? defaultFormData.settings.cookie_duration,
          max_displays: defaultFormData.settings.max_displays,
        },
      });
    }
  }, [popup]);

  const saveMutation = useMutation({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    mutationFn: (data: any) =>
      isEditing ? popupsApi.update(Number(id), data) : popupsApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['popups'] });
      navigate('/popups');
    },
  });

  const handleSave = (status?: string) => {
    // Convert local form data to API format
    const apiData = {
      name: formData.name,
      type: formData.type,
      status: status || formData.status,
      position: formData.style.position,
      title: formData.content.title,
      content: formData.content.body,
      image_url: formData.content.image_url || undefined,
      button_text: formData.content.cta_text,
      triggers: {
        type: formData.trigger.type,
        delay: formData.trigger.type === 'time_delay' ? Number(formData.trigger.value) : undefined,
        percent: formData.trigger.type === 'scroll_percent' ? Number(formData.trigger.value) : undefined,
        selector: ['scroll_element', 'click'].includes(formData.trigger.type) ? String(formData.trigger.value) : undefined,
        count: formData.trigger.type === 'page_views' ? Number(formData.trigger.value) : undefined,
        timeout: formData.trigger.type === 'inactivity' ? Number(formData.trigger.value) : undefined,
      },
      styles: {
        background_color: formData.style.background_color,
        text_color: formData.style.text_color,
        button_color: formData.style.button_color,
        button_text_color: formData.style.button_text_color,
        border_radius: formData.style.border_radius,
        max_width: parseInt(formData.style.width) || 480,
      },
      settings: {
        overlay: true,
        overlay_color: formData.style.overlay_color,
        close_button: formData.settings.show_close_button,
        close_on_overlay: formData.settings.close_on_overlay,
        hide_after_dismiss_days: formData.settings.cookie_duration,
      },
    };
    saveMutation.mutate(apiData);
  };

  const updateContent = (field: string, value: string) => {
    setFormData((prev) => ({
      ...prev,
      content: { ...prev.content, [field]: value },
    }));
  };

  const updateTrigger = (field: string, value: string | number) => {
    setFormData((prev) => ({
      ...prev,
      trigger: { ...prev.trigger, [field]: value },
    }));
  };

  const updateStyle = (field: string, value: string | number) => {
    setFormData((prev) => ({
      ...prev,
      style: { ...prev.style, [field]: value },
    }));
  };

  const updateSettings = (field: string, value: boolean | number) => {
    setFormData((prev) => ({
      ...prev,
      settings: { ...prev.settings, [field]: value },
    }));
  };

  const tabs = [
    { id: 'content' as Tab, label: 'Content', icon: Layout },
    { id: 'trigger' as Tab, label: 'Trigger', icon: MousePointer },
    { id: 'targeting' as Tab, label: 'Targeting', icon: Target },
    { id: 'style' as Tab, label: 'Style', icon: Paintbrush },
  ];

  if (isLoading) {
    return (
      <PageLayout title="Loading..." description="">
        <div className="animate-pulse space-y-4">
          <div className="h-10 bg-slate-200 rounded w-1/3" />
          <div className="h-64 bg-slate-200 rounded" />
        </div>
      </PageLayout>
    );
  }

  return (
    <PageLayout
      title={isEditing ? 'Edit Popup' : 'Create Popup'}
      description={isEditing ? `Editing: ${formData.name}` : 'Build a new conversion popup'}
    >
      {/* Header Actions */}
      <div className="flex items-center justify-between mb-6">
        <Button variant="outline" onClick={() => navigate('/popups')} icon={<ArrowLeft className="w-4 h-4" />}>
          Back to Popups
        </Button>
        <div className="flex items-center gap-3">
          <Button variant="outline" icon={<Eye className="w-4 h-4" />}>
            Preview
          </Button>
          <Button
            variant="outline"
            onClick={() => handleSave('draft')}
            loading={saveMutation.isPending}
          >
            Save Draft
          </Button>
          <Button
            onClick={() => handleSave('active')}
            loading={saveMutation.isPending}
            icon={<Save className="w-4 h-4" />}
          >
            Publish
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Editor Panel */}
        <div className="lg:col-span-2 space-y-6">
          {/* Basic Info */}
          <Card>
            <h3 className="text-lg font-semibold text-slate-900 mb-4">Basic Information</h3>
            <div className="grid grid-cols-2 gap-4">
              <Input
                label="Popup Name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="My Popup"
                required
              />
              <Select
                label="Popup Type"
                options={popupTypes}
                value={formData.type}
                onChange={(e) => setFormData({ ...formData, type: e.target.value })}
              />
            </div>
          </Card>

          {/* Tab Navigation */}
          <div className="flex gap-1 bg-slate-100 p-1 rounded-lg">
            {tabs.map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={`flex-1 flex items-center justify-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                  activeTab === tab.id
                    ? 'bg-white text-slate-900 shadow-sm'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                <tab.icon className="w-4 h-4" />
                {tab.label}
              </button>
            ))}
          </div>

          {/* Tab Content */}
          {activeTab === 'content' && (
            <Card>
              <h3 className="text-lg font-semibold text-slate-900 mb-4">Popup Content</h3>
              <div className="space-y-4">
                <Input
                  label="Headline"
                  value={formData.content.title}
                  onChange={(e) => updateContent('title', e.target.value)}
                  placeholder="Your attention-grabbing headline"
                />
                <Textarea
                  label="Body Text"
                  value={formData.content.body}
                  onChange={(e) => updateContent('body', e.target.value)}
                  placeholder="Supporting text that explains your offer..."
                  rows={3}
                />
                <div className="grid grid-cols-2 gap-4">
                  <Input
                    label="Button Text"
                    value={formData.content.cta_text}
                    onChange={(e) => updateContent('cta_text', e.target.value)}
                    placeholder="Subscribe"
                  />
                  <Input
                    label="Button URL (optional)"
                    value={formData.content.cta_url}
                    onChange={(e) => updateContent('cta_url', e.target.value)}
                    placeholder="https://..."
                  />
                </div>
                <Input
                  label="Image URL (optional)"
                  value={formData.content.image_url}
                  onChange={(e) => updateContent('image_url', e.target.value)}
                  placeholder="https://example.com/image.jpg"
                />
              </div>
            </Card>
          )}

          {activeTab === 'trigger' && (
            <Card>
              <h3 className="text-lg font-semibold text-slate-900 mb-4">Trigger Settings</h3>
              <div className="space-y-4">
                <Select
                  label="Trigger Type"
                  options={triggerTypes}
                  value={formData.trigger.type}
                  onChange={(e) => updateTrigger('type', e.target.value)}
                />
                <TriggerValueInput
                  type={formData.trigger.type}
                  value={formData.trigger.value}
                  onChange={(value) => updateTrigger('value', value)}
                />
              </div>
            </Card>
          )}

          {activeTab === 'targeting' && (
            <Card>
              <h3 className="text-lg font-semibold text-slate-900 mb-4">Targeting Rules</h3>
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-2">Show on Devices</label>
                  <div className="flex gap-4">
                    {['desktop', 'tablet', 'mobile'].map((device) => (
                      <label key={device} className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={formData.targeting.devices.includes(device)}
                          onChange={(e) => {
                            const devices = e.target.checked
                              ? [...formData.targeting.devices, device]
                              : formData.targeting.devices.filter((d) => d !== device);
                            setFormData((prev) => ({
                              ...prev,
                              targeting: { ...prev.targeting, devices },
                            }));
                          }}
                          className="rounded border-slate-300 text-primary-600"
                        />
                        <span className="text-sm capitalize">{device}</span>
                      </label>
                    ))}
                  </div>
                </div>
                <Select
                  label="Show To"
                  options={[
                    { value: 'all', label: 'All Visitors' },
                    { value: 'new', label: 'New Visitors Only' },
                    { value: 'returning', label: 'Returning Visitors Only' },
                  ]}
                  value={formData.targeting.show_to}
                  onChange={(e) =>
                    setFormData((prev) => ({
                      ...prev,
                      targeting: { ...prev.targeting, show_to: e.target.value },
                    }))
                  }
                />
                <Input
                  label="Show on Pages (comma-separated URLs, leave empty for all)"
                  value={formData.targeting.pages.join(', ')}
                  onChange={(e) =>
                    setFormData((prev) => ({
                      ...prev,
                      targeting: {
                        ...prev.targeting,
                        pages: e.target.value.split(',').map((s) => s.trim()).filter(Boolean),
                      },
                    }))
                  }
                  placeholder="/pricing, /features, /about"
                />
              </div>
            </Card>
          )}

          {activeTab === 'style' && (
            <Card>
              <h3 className="text-lg font-semibold text-slate-900 mb-4">Appearance</h3>
              <div className="space-y-4">
                <Select
                  label="Position"
                  options={positionOptions}
                  value={formData.style.position}
                  onChange={(e) => updateStyle('position', e.target.value)}
                />
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5" htmlFor="popupbuilder-background-color">Background Color</label>
                    <input id="popupbuilder-background-color"
                      type="color"
                      value={formData.style.background_color}
                      onChange={(e) => updateStyle('background_color', e.target.value)}
                      className="w-full h-10 rounded-lg border border-slate-200 cursor-pointer"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5" htmlFor="popupbuilder-text-color">Text Color</label>
                    <input id="popupbuilder-text-color"
                      type="color"
                      value={formData.style.text_color}
                      onChange={(e) => updateStyle('text_color', e.target.value)}
                      className="w-full h-10 rounded-lg border border-slate-200 cursor-pointer"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5" htmlFor="popupbuilder-button-color">Button Color</label>
                    <input id="popupbuilder-button-color"
                      type="color"
                      value={formData.style.button_color}
                      onChange={(e) => updateStyle('button_color', e.target.value)}
                      className="w-full h-10 rounded-lg border border-slate-200 cursor-pointer"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5" htmlFor="popupbuilder-button-text-color">Button Text Color</label>
                    <input id="popupbuilder-button-text-color"
                      type="color"
                      value={formData.style.button_text_color}
                      onChange={(e) => updateStyle('button_text_color', e.target.value)}
                      className="w-full h-10 rounded-lg border border-slate-200 cursor-pointer"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Border Radius: {formData.style.border_radius}px
                  </label>
                  <input
                    type="range"
                    min="0"
                    max="32"
                    value={formData.style.border_radius}
                    onChange={(e) => updateStyle('border_radius', Number(e.target.value))}
                    className="w-full"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Overlay Opacity: {formData.style.overlay_opacity}%
                  </label>
                  <input
                    type="range"
                    min="0"
                    max="100"
                    value={formData.style.overlay_opacity}
                    onChange={(e) => updateStyle('overlay_opacity', Number(e.target.value))}
                    className="w-full"
                  />
                </div>
              </div>
            </Card>
          )}
        </div>

        {/* Preview Panel */}
        <div className="lg:col-span-1">
          <Card className="sticky top-6">
            <h3 className="text-lg font-semibold text-slate-900 mb-4">Live Preview</h3>
            <div
              className="relative bg-slate-100 rounded-lg overflow-hidden"
              style={{ minHeight: '400px' }}
            >
              <PopupPreview formData={formData} />
            </div>
            <div className="mt-4 space-y-3">
              <h4 className="text-sm font-medium text-slate-700">Display Settings</h4>
              <label className="flex items-center justify-between">
                <span className="text-sm text-slate-600">Show close button</span>
                <input
                  type="checkbox"
                  checked={formData.settings.show_close_button}
                  onChange={(e) => updateSettings('show_close_button', e.target.checked)}
                  className="rounded border-slate-300 text-primary-600"
                />
              </label>
              <label className="flex items-center justify-between">
                <span className="text-sm text-slate-600">Close on overlay click</span>
                <input
                  type="checkbox"
                  checked={formData.settings.close_on_overlay}
                  onChange={(e) => updateSettings('close_on_overlay', e.target.checked)}
                  className="rounded border-slate-300 text-primary-600"
                />
              </label>
              <Input
                label="Cookie duration (days)"
                type="number"
                value={formData.settings.cookie_duration}
                onChange={(e) => updateSettings('cookie_duration', Number(e.target.value))}
                min={0}
              />
              <Input
                label="Max displays per visitor"
                type="number"
                value={formData.settings.max_displays}
                onChange={(e) => updateSettings('max_displays', Number(e.target.value))}
                min={0}
              />
            </div>
          </Card>
        </div>
      </div>
    </PageLayout>
  );
}

// Trigger value input based on trigger type
function TriggerValueInput({
  type,
  value,
  onChange,
}: {
  type: string;
  value: number | string;
  onChange: (value: number | string) => void;
}) {
  switch (type) {
    case 'time_delay':
      return (
        <Input
          label="Delay (seconds)"
          type="number"
          value={value}
          onChange={(e) => onChange(Number(e.target.value))}
          min={0}
          helper="Show popup after this many seconds"
        />
      );
    case 'scroll_percent':
      return (
        <Input
          label="Scroll Percentage"
          type="number"
          value={value}
          onChange={(e) => onChange(Number(e.target.value))}
          min={0}
          max={100}
          helper="Show when visitor scrolls this far down the page"
        />
      );
    case 'scroll_element':
      return (
        <Input
          label="Element Selector"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder="#my-element, .my-class"
          helper="CSS selector for the element to trigger on"
        />
      );
    case 'click':
      return (
        <Input
          label="Click Selector"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder=".popup-trigger, #open-popup"
          helper="CSS selector for clickable elements"
        />
      );
    case 'page_views':
      return (
        <Input
          label="Page View Count"
          type="number"
          value={value}
          onChange={(e) => onChange(Number(e.target.value))}
          min={1}
          helper="Show after visitor views this many pages"
        />
      );
    case 'inactivity':
      return (
        <Input
          label="Inactivity Time (seconds)"
          type="number"
          value={value}
          onChange={(e) => onChange(Number(e.target.value))}
          min={1}
          helper="Show after this many seconds of no interaction"
        />
      );
    case 'exit_intent':
      return (
        <p className="text-sm text-slate-500">
          Popup will show when the visitor moves their cursor toward the browser's close button or address bar.
        </p>
      );
    default:
      return null;
  }
}

// Live preview component
function PopupPreview({ formData }: { formData: PopupFormData }) {
  const { content, style, settings, type } = formData;

  const getPopupClasses = () => {
    const base = 'absolute transition-all';
    switch (style.position) {
      case 'top-left':
        return `${base} top-4 left-4`;
      case 'top-right':
        return `${base} top-4 right-4`;
      case 'bottom-left':
        return `${base} bottom-4 left-4`;
      case 'bottom-right':
        return `${base} bottom-4 right-4`;
      default:
        return `${base} top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2`;
    }
  };

  if (type === 'bar') {
    return (
      <div
        className="absolute top-0 left-0 right-0 p-3 flex items-center justify-between"
        style={{ backgroundColor: style.background_color, color: style.text_color }}
      >
        <span className="text-sm font-medium">{content.title}</span>
        <button
          className="px-3 py-1 text-sm rounded"
          style={{ backgroundColor: style.button_color, color: style.button_text_color }}
        >
          {content.cta_text}
        </button>
      </div>
    );
  }

  return (
    <>
      {/* Overlay */}
      <div
        className="absolute inset-0"
        style={{
          backgroundColor: style.overlay_color,
          opacity: style.overlay_opacity / 100,
        }}
      />

      {/* Popup */}
      <div
        className={getPopupClasses()}
        style={{
          backgroundColor: style.background_color,
          color: style.text_color,
          borderRadius: `${style.border_radius}px`,
          width: type === 'fullscreen' ? '90%' : 'min(280px, 90%)',
          maxHeight: '90%',
          boxShadow: '0 20px 25px -5px rgb(0 0 0 / 0.1)',
        }}
      >
        {settings.show_close_button && (
          <button className="absolute top-2 right-2 text-slate-400 hover:text-slate-600">
            <span className="text-xl">&times;</span>
          </button>
        )}

        {content.image_url && (
          <img
            src={content.image_url}
            alt=""
            className="w-full h-24 object-cover"
            style={{ borderTopLeftRadius: `${style.border_radius}px`, borderTopRightRadius: `${style.border_radius}px` }}
          />
        )}

        <div className="p-4">
          <h3 className="text-lg font-semibold mb-2">{content.title || 'Headline'}</h3>
          <p className="text-sm opacity-80 mb-4">{content.body || 'Body text...'}</p>
          <button
            className="w-full py-2 px-4 rounded-lg text-sm font-medium"
            style={{
              backgroundColor: style.button_color,
              color: style.button_text_color,
            }}
          >
            {content.cta_text || 'Button'}
          </button>
        </div>
      </div>
    </>
  );
}
