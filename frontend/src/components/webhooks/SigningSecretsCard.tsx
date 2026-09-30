import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { AlertCircle, Copy, KeyRound, ShieldAlert, ShieldCheck } from 'lucide-react';
import { Card, CardHeader, Button, Input, Badge, Modal, ConfirmModal } from '../common';
import { webhooksApi } from '../../api/endpoints';
import { toast } from '../../store';
import type { WebhookSigningSource } from '../../types';

const SOURCE_PATTERN = /^[A-Za-z0-9._-]{1,64}$/;
const MIN_SECRET_LENGTH = 16;

/** Where to paste the secret, per known sender. */
const senderHelp: Record<string, string> = {
  'formflow-lite':
    'In FormFlow Lite, edit the webhook that points at this site and paste it into its Secret field. FormFlow Lite signs with the X-FFFL-Signature header.',
  formflow:
    'FormFlow Pro does not send a source name, so its webhooks arrive as "unknown". Set the secret on "unknown" and paste it into the FormFlow Pro webhook\'s Secret field (it signs with X-ISF-Signature).',
  unknown:
    'Webhooks with no source name arrive as "unknown" (FormFlow Pro among them). Paste this into the sender\'s webhook secret setting.',
};

const genericHelp =
  'Paste it into the sender\'s webhook secret setting. The sender must sign the raw request body with HMAC-SHA256 and send the hex digest as X-Webhook-Signature: sha256=<digest>, and name itself with an X-Webhook-Source header or a "source" field.';

function statusBadge(row: WebhookSigningSource) {
  if (row.signed && !row.readable) {
    return <Badge variant="danger">Secret unreadable</Badge>;
  }
  if (row.signed) {
    return <Badge variant="success">Signature required</Badge>;
  }
  return <Badge variant="warning">Unsigned</Badge>;
}

export default function SigningSecretsCard() {
  const queryClient = useQueryClient();
  const [newSource, setNewSource] = useState('');
  const [pasteFor, setPasteFor] = useState<string | null>(null);
  const [pastedSecret, setPastedSecret] = useState('');
  const [rotateFor, setRotateFor] = useState<string | null>(null);
  const [clearFor, setClearFor] = useState<string | null>(null);
  const [revealed, setRevealed] = useState<{ source: string; secret: string } | null>(null);

  const { data: status, isLoading } = useQuery({
    queryKey: ['webhooks-signing'],
    queryFn: webhooksApi.getSigningStatus,
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['webhooks-signing'] });

  const generateMutation = useMutation({
    mutationFn: webhooksApi.generateSigningSecret,
    onSuccess: (result) => {
      refresh();
      setRotateFor(null);
      setNewSource('');
      if (result.secret) {
        setRevealed({ source: result.source, secret: result.secret });
      }
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : 'Could not generate a secret'),
  });

  const pasteMutation = useMutation({
    mutationFn: ({ source, secret }: { source: string; secret: string }) =>
      webhooksApi.setSigningSecret(source, secret),
    onSuccess: (result) => {
      refresh();
      setPasteFor(null);
      setPastedSecret('');
      toast.success(`Signing secret saved for ${result.source}`);
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : 'Could not save the secret'),
  });

  const clearMutation = useMutation({
    mutationFn: webhooksApi.clearSigningSecret,
    onSuccess: (result) => {
      refresh();
      setClearFor(null);
      toast.success(`${result.source} now accepts unsigned webhooks`);
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : 'Could not clear the secret'),
  });

  const copy = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success('Copied');
  };

  const newSourceValid = SOURCE_PATTERN.test(newSource);
  const unsigned = status?.unsigned_seen_sources ?? [];

  return (
    <Card className="mb-6">
      <CardHeader
        title="Signing secrets"
        description="When a source has a signing secret, its webhooks must carry a valid HMAC-SHA256 signature of the request body or they are rejected. Sources without a secret are accepted unsigned."
      />

      {unsigned.length > 0 && (
        <div
          role="status"
          className="mt-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20"
        >
          <ShieldAlert className="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400" aria-hidden="true" />
          <p className="text-sm text-amber-800 dark:text-amber-200">
            {unsigned.length === 1 ? 'One source is' : `${unsigned.length} sources are`} delivering webhooks
            without a signature: <strong>{unsigned.join(', ')}</strong>. Anyone who knows the endpoint URL can
            post as {unsigned.length === 1 ? 'it' : 'them'}. Generate a secret and add it to the sender.
          </p>
        </div>
      )}

      {status?.endpoint_url && (
        <div className="mt-4">
          <span className="block text-sm font-medium text-slate-700 dark:text-slate-300">Endpoint URL</span>
          <div className="mt-1 flex items-center gap-2">
            <code className="flex-1 break-all rounded-lg bg-slate-100 p-2 text-sm dark:bg-slate-800">
              {status.endpoint_url}
            </code>
            <Button variant="ghost" size="sm" onClick={() => copy(status.endpoint_url)} title="Copy endpoint URL">
              <Copy className="h-4 w-4" aria-hidden="true" />
              <span className="sr-only">Copy endpoint URL</span>
            </Button>
          </div>
        </div>
      )}

      <div className="mt-4 overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-100 text-left text-slate-500 dark:border-slate-700">
              <th scope="col" className="py-2 pr-4 font-medium">Source</th>
              <th scope="col" className="py-2 pr-4 font-medium">Status</th>
              <th scope="col" className="py-2 pr-4 font-medium">Activity</th>
              <th scope="col" className="py-2 font-medium"><span className="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr>
                <td colSpan={4} className="py-4 text-slate-500">Loading signing status…</td>
              </tr>
            )}
            {status?.sources.map((row) => (
              <tr key={row.source} className="border-b border-slate-50 dark:border-slate-800">
                <td className="py-2 pr-4 font-mono">{row.source}</td>
                <td className="py-2 pr-4">
                  {statusBadge(row)}
                  {row.signed && !row.readable && (
                    <p className="mt-1 text-xs text-red-700 dark:text-red-300">
                      Every webhook from this source is rejected until you set a new secret.
                    </p>
                  )}
                </td>
                <td className="py-2 pr-4 text-slate-500">{row.seen ? 'Has sent webhooks' : 'Not seen yet'}</td>
                <td className="py-2">
                  <div className="flex flex-wrap justify-end gap-2">
                    <Button
                      variant={row.signed ? 'outline' : 'primary'}
                      size="sm"
                      onClick={() => (row.signed ? setRotateFor(row.source) : generateMutation.mutate(row.source))}
                      disabled={generateMutation.isPending}
                    >
                      <KeyRound className="mr-1 h-4 w-4" aria-hidden="true" />
                      {row.signed ? 'Rotate' : 'Generate secret'}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setPasteFor(row.source)}>
                      Use sender's secret
                    </Button>
                    {row.signed && (
                      <Button variant="ghost" size="sm" onClick={() => setClearFor(row.source)}>
                        Clear
                      </Button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <form
        className="mt-4 flex flex-wrap items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          if (newSourceValid) {
            generateMutation.mutate(newSource);
          }
        }}
      >
        <div className="min-w-[14rem] flex-1">
          <Input
            id="webhook-signing-new-source"
            label="Add a source"
            placeholder="e.g. zapier"
            value={newSource}
            onChange={(e) => setNewSource(e.target.value.trim())}
            error={newSource !== '' && !newSourceValid ? 'Letters, numbers, dots, dashes and underscores only.' : undefined}
          />
        </div>
        <Button type="submit" disabled={!newSourceValid || generateMutation.isPending}>
          Generate secret
        </Button>
      </form>

      <details className="mt-4 text-sm text-slate-600 dark:text-slate-400">
        <summary className="cursor-pointer font-medium text-slate-700 dark:text-slate-300">Which senders sign, and how</summary>
        <ul className="mt-2 list-disc space-y-1 pl-5">
          <li>
            <strong>FormFlow Lite</strong> (source <code>formflow-lite</code>): signs when its webhook has a Secret, using
            the <code>X-FFFL-Signature</code> header.
          </li>
          <li>
            <strong>FormFlow Pro</strong>: signs with <code>X-ISF-Signature</code> but sends no source name, so it
            arrives as <code>unknown</code>. Put its secret on <code>unknown</code>.
          </li>
          <li>
            <strong>Anything else</strong>: HMAC-SHA256 of the raw body, hex, sent as{' '}
            <code>X-Webhook-Signature: sha256=&lt;digest&gt;</code>; name the source with an{' '}
            <code>X-Webhook-Source</code> header or a <code>source</code> field.
          </li>
          <li>Secrets are stored encrypted. A generated secret is shown once; rotating replaces it immediately.</li>
        </ul>
      </details>

      {/* Show-once modal */}
      <Modal isOpen={!!revealed} onClose={() => setRevealed(null)} title="Copy your signing secret" size="lg">
        {revealed && (
          <div className="space-y-4">
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
              <div className="flex items-start gap-3">
                <AlertCircle className="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                <div>
                  <p className="text-sm font-medium text-amber-800 dark:text-amber-200">This is the only time you'll see this secret.</p>
                  <p className="mt-1 text-sm text-amber-700 dark:text-amber-300">
                    Webhooks from <strong>{revealed.source}</strong> are rejected until the sender signs with it.
                  </p>
                </div>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <code className="flex-1 break-all rounded-lg bg-slate-100 p-3 font-mono text-sm dark:bg-slate-800">
                {revealed.secret}
              </code>
              <Button variant="ghost" size="sm" onClick={() => copy(revealed.secret)} title="Copy secret">
                <Copy className="h-4 w-4" aria-hidden="true" />
                <span className="sr-only">Copy secret</span>
              </Button>
            </div>
            <p className="text-sm text-slate-600 dark:text-slate-400">{senderHelp[revealed.source] ?? genericHelp}</p>
            <div className="flex justify-end pt-2">
              <Button onClick={() => setRevealed(null)}>
                <ShieldCheck className="mr-1 h-4 w-4" aria-hidden="true" />
                I've copied it
              </Button>
            </div>
          </div>
        )}
      </Modal>

      {/* Paste a sender-issued secret */}
      <Modal
        isOpen={!!pasteFor}
        onClose={() => {
          setPasteFor(null);
          setPastedSecret('');
        }}
        title={`Use the sender's secret for ${pasteFor ?? ''}`}
      >
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            if (pasteFor && pastedSecret.length >= MIN_SECRET_LENGTH) {
              pasteMutation.mutate({ source: pasteFor, secret: pastedSecret });
            }
          }}
        >
          <p className="text-sm text-slate-600 dark:text-slate-400">
            For senders that issue their own signing secret. It is stored encrypted and never shown again.
          </p>
          <Input
            id="webhook-signing-pasted-secret"
            label="Signing secret"
            type="password"
            autoComplete="off"
            value={pastedSecret}
            onChange={(e) => setPastedSecret(e.target.value)}
            hint={`At least ${MIN_SECRET_LENGTH} characters.`}
          />
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => setPasteFor(null)}>
              Cancel
            </Button>
            <Button type="submit" disabled={pastedSecret.length < MIN_SECRET_LENGTH} loading={pasteMutation.isPending}>
              Save secret
            </Button>
          </div>
        </form>
      </Modal>

      <ConfirmModal
        isOpen={!!rotateFor}
        onClose={() => setRotateFor(null)}
        onConfirm={() => rotateFor && generateMutation.mutate(rotateFor)}
        title={`Rotate the secret for ${rotateFor ?? ''}`}
        message="The current secret stops working immediately. Webhooks from this source are rejected until you paste the new secret into the sender."
        confirmText="Rotate secret"
        variant="danger"
        loading={generateMutation.isPending}
      />

      <ConfirmModal
        isOpen={!!clearFor}
        onClose={() => setClearFor(null)}
        onConfirm={() => clearFor && clearMutation.mutate(clearFor)}
        title={`Clear the secret for ${clearFor ?? ''}`}
        message="This source will accept unsigned webhooks again, from anyone who knows the endpoint URL."
        confirmText="Clear secret"
        variant="danger"
        loading={clearMutation.isPending}
      />
    </Card>
  );
}
