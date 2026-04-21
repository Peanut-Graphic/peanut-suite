import { useState, useRef } from 'react';
import { QRCodeCanvas } from 'qrcode.react';
import { Download, Copy, Check, X } from 'lucide-react';
import Button from './Button';

interface QRCodeDisplayProps {
  value: string;
  size?: number;
  title?: string;
}

export function QRCodeDisplay({ value, size = 200, title }: QRCodeDisplayProps) {
  const [copied, setCopied] = useState(false);
  const canvasRef = useRef<HTMLDivElement>(null);

  const handleCopy = async () => {
    try {
      // Get the canvas element inside our container
      const canvas = canvasRef.current?.querySelector('canvas');
      if (!canvas) return;

      // Convert canvas to blob
      const blob = await new Promise<Blob>((resolve) => {
        canvas.toBlob((b) => resolve(b!), 'image/png');
      });

      // Copy to clipboard
      await navigator.clipboard.write([
        new ClipboardItem({ 'image/png': blob }),
      ]);

      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch (err) {
      console.error('Failed to copy QR code:', err);
    }
  };

  const handleDownload = () => {
    const canvas = canvasRef.current?.querySelector('canvas');
    if (!canvas) return;

    const link = document.createElement('a');
    link.download = `qr-code${title ? `-${title.toLowerCase().replace(/\s+/g, '-')}` : ''}.png`;
    link.href = canvas.toDataURL('image/png');
    link.click();
  };

  return (
    <div className="flex flex-col items-center">
      <div ref={canvasRef} className="p-4 bg-white rounded-lg border border-slate-200">
        <QRCodeCanvas
          value={value}
          size={size}
          level="H"
          includeMargin
          bgColor="#ffffff"
          fgColor="#000000"
        />
      </div>
      {title && (
        <p className="mt-2 text-sm font-medium text-slate-700 text-center">{title}</p>
      )}
      <p className="mt-1 text-xs text-slate-400 text-center truncate max-w-full">
        {value}
      </p>
      <div className="flex gap-2 mt-4">
        <Button
          variant="outline"
          size="sm"
          onClick={handleCopy}
          icon={copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
        >
          {copied ? 'Copied!' : 'Copy'}
        </Button>
        <Button
          variant="outline"
          size="sm"
          onClick={handleDownload}
          icon={<Download className="w-4 h-4" />}
        >
          Download
        </Button>
      </div>
    </div>
  );
}

interface QRCodeModalProps {
  isOpen: boolean;
  onClose: () => void;
  value: string;
  title?: string;
}

export function QRCodeModal({ isOpen, onClose, value, title }: QRCodeModalProps) {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center p-3 sm:items-center sm:p-4">
      {/* Backdrop */}
      <button
        type="button"
        className="absolute inset-0 bg-black/50"
        onClick={onClose}
        aria-label="Close QR code dialog"
      />

      {/* Modal */}
      <div
        className="relative max-h-[calc(100dvh-1.5rem)] w-full max-w-sm overflow-y-auto rounded-t-2xl bg-white p-6 shadow-xl sm:max-h-[calc(100dvh-2rem)] sm:rounded-xl"
        role="dialog"
        aria-modal="true"
        aria-label="QR code"
      >
        <button
          onClick={onClose}
          className="absolute top-4 right-4 p-1 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100"
          aria-label="Close QR code dialog"
        >
          <X className="w-5 h-5" />
        </button>

        <h3 className="text-lg font-semibold text-slate-900 mb-4 text-center">
          QR Code
        </h3>

        <QRCodeDisplay value={value} size={240} title={title} />
      </div>
    </div>
  );
}

export default QRCodeDisplay;
