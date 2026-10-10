import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { axe, toHaveNoViolations } from 'jest-axe';
import SigningSecretsCard from '../../components/webhooks/SigningSecretsCard';
import { webhooksApi } from '../../api/endpoints';

expect.extend(toHaveNoViolations);

// The common/ barrel loads the theme store, which reads matchMedia at import.
vi.hoisted(() => {
  if (!window.matchMedia) {
    window.matchMedia = ((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    })) as unknown as typeof window.matchMedia;
  }
});

vi.mock('../../api/endpoints', () => ({
  webhooksApi: {
    getSigningStatus: vi.fn(),
    generateSigningSecret: vi.fn(),
    setSigningSecret: vi.fn(),
    clearSigningSecret: vi.fn(),
  },
}));

const api = vi.mocked(webhooksApi);
const SECRET = 'a'.repeat(64);

function renderCard() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <SigningSecretsCard />
    </QueryClientProvider>
  );
}

describe('SigningSecretsCard', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    Object.assign(navigator, { clipboard: { writeText: vi.fn() } });
    api.getSigningStatus.mockResolvedValue({
      endpoint_url: 'https://suite.example/wp-json/peanut/v1/webhooks/receive',
      sources: [
        { source: 'formflow', signed: false, readable: false, seen: false },
        { source: 'formflow-lite', signed: true, readable: true, seen: true },
        { source: 'stripe', signed: true, readable: false, seen: false },
        { source: 'zapier', signed: false, readable: false, seen: true },
      ],
      unsigned_seen_sources: ['zapier'],
    });
  });

  it('flags sources without a secret as rejected, and unreadable secrets', async () => {
    renderCard();

    const banner = await screen.findByRole('status');
    expect(banner).toHaveTextContent('zapier');
    expect(banner).toHaveTextContent(/no signing secret/);
    expect(banner).toHaveTextContent(/rejected/);
    expect(banner).not.toHaveTextContent(/accepted unsigned/);
    expect(screen.getByText('Secret unreadable')).toBeInTheDocument();
    expect(screen.getAllByText('Signature required')).toHaveLength(1);
    expect(screen.getAllByText('No secret: rejected')).toHaveLength(2);
    expect(screen.queryByText(/Sources without a secret are accepted unsigned/)).not.toBeInTheDocument();
  });

  it('names senders whose unsigned webhooks were refused, and filter opt-ins', async () => {
    api.getSigningStatus.mockResolvedValue({
      endpoint_url: 'https://suite.example/wp-json/peanut/v1/webhooks/receive',
      sources: [
        { source: 'formflow-lite', signed: true, readable: true, seen: true },
        { source: 'legacy-crm', signed: false, readable: false, seen: false, rejected_unsigned: true },
        { source: 'nocode', signed: false, readable: false, seen: true, allows_unsigned: true },
      ],
      unsigned_seen_sources: [],
      rejected_unsigned_sources: ['legacy-crm'],
    });
    renderCard();

    const banner = await screen.findByRole('status');
    expect(banner).toHaveTextContent('legacy-crm');
    expect(screen.getByText('Unsigned (allowed by filter)')).toBeInTheDocument();
  });

  it('warns that clearing a secret makes the source rejected', async () => {
    renderCard();

    const row = (await screen.findByText('formflow-lite', { selector: 'td' })).closest('tr') as HTMLElement;
    fireEvent.click(within(row).getByRole('button', { name: /clear/i }));

    expect(await screen.findByText(/will be rejected until you set a new secret/i)).toBeInTheDocument();
  });

  it('shows a generated secret once, with sender instructions', async () => {
    api.generateSigningSecret.mockResolvedValue({ source: 'zapier', signed: true, secret: SECRET });
    renderCard();

    const row = (await screen.findByText('zapier', { selector: 'td' })).closest('tr') as HTMLElement;
    fireEvent.click(within(row).getByRole('button', { name: /generate secret/i }));

    await waitFor(() => expect(screen.getByText(SECRET)).toBeInTheDocument());
    expect(api.generateSigningSecret.mock.calls[0][0]).toBe('zapier');
    expect(screen.getByText(/only time you'll see this secret/i)).toBeInTheDocument();
    expect(screen.getByText(/must sign the raw request body/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /i've copied it/i }));
    await waitFor(() => expect(screen.queryByText(SECRET)).not.toBeInTheDocument());
  });

  it('asks before rotating an existing secret', async () => {
    renderCard();

    const row = (await screen.findByText('formflow-lite', { selector: 'td' })).closest('tr') as HTMLElement;
    fireEvent.click(within(row).getByRole('button', { name: /rotate/i }));

    expect(await screen.findByText(/stops working immediately/i)).toBeInTheDocument();
    expect(api.generateSigningSecret).not.toHaveBeenCalled();
  });

  it('has no axe violations', async () => {
    const { container } = renderCard();
    await screen.findByRole('status');

    expect(await axe(container)).toHaveNoViolations();
  });
});
