import { Fragment, type ReactNode, useEffect, useRef } from 'react';
import { X } from 'lucide-react';
import { clsx } from 'clsx';
import Button from './Button';
import useKeyboardShortcuts from '@/hooks/useKeyboardShortcuts';

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title?: string;
  description?: string;
  children: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  showClose?: boolean;
  className?: string;
}

export default function Modal({
  isOpen,
  onClose,
  title,
  description,
  children,
  size = 'md',
  showClose = true,
  className,
}: ModalProps) {
  const modalRef = useRef<HTMLDivElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);

  // Handle Escape key to close modal
  useKeyboardShortcuts(
    [
      {
        key: 'Escape',
        callback: () => onClose(),
        description: 'Close modal',
      },
    ],
    { enabled: isOpen }
  );

  // Focus management
  useEffect(() => {
    if (isOpen) {
      // Store the previously focused element
      previousFocusRef.current = document.activeElement as HTMLElement;

      // Focus the modal
      requestAnimationFrame(() => {
        modalRef.current?.focus();
      });
    } else if (previousFocusRef.current) {
      // Restore focus when modal closes
      previousFocusRef.current.focus();
      previousFocusRef.current = null;
    }
  }, [isOpen]);

  // Trap focus within modal
  useEffect(() => {
    if (!isOpen || !modalRef.current) return;

    const handleTabKey = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return;

      const focusableElements = modalRef.current?.querySelectorAll(
        'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      );

      if (!focusableElements || focusableElements.length === 0) return;

      const firstElement = focusableElements[0] as HTMLElement;
      const lastElement = focusableElements[focusableElements.length - 1] as HTMLElement;

      if (e.shiftKey && document.activeElement === firstElement) {
        e.preventDefault();
        lastElement.focus();
      } else if (!e.shiftKey && document.activeElement === lastElement) {
        e.preventDefault();
        firstElement.focus();
      }
    };

    document.addEventListener('keydown', handleTabKey);
    return () => document.removeEventListener('keydown', handleTabKey);
  }, [isOpen]);

  if (!isOpen) return null;

  const sizes = {
    sm: 'max-w-md',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
  };

  return (
    <Fragment>
      {/* Backdrop */}
      <button
        type="button"
        className="fixed inset-0 bg-black/50 dark:bg-black/70 z-[100] transition-opacity"
        onClick={onClose}
        aria-label="Close modal"
      />

      {/* Modal */}
      <div
        className="fixed inset-0 z-[101] overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? 'modal-title' : undefined}
        aria-describedby={description ? 'modal-description' : undefined}
      >
        <div className="flex min-h-[100dvh] items-end justify-center p-3 sm:items-center sm:p-4">
          <div
            ref={modalRef}
            tabIndex={-1}
            className={clsx(
              'relative w-full max-h-[calc(100dvh-1.5rem)] overflow-hidden rounded-t-2xl bg-white shadow-xl transition-all focus:outline-none dark:bg-slate-800 sm:max-h-[calc(100dvh-2rem)] sm:rounded-xl',
              sizes[size],
              className
            )}
            onClick={(e) => e.stopPropagation()}
          >
            {/* Header */}
            {(title || showClose) && (
              <div className="flex items-start justify-between p-5 border-b border-slate-200 dark:border-slate-700">
                <div>
                  {title && (
                    <h3 id="modal-title" className="text-lg font-semibold text-slate-900 dark:text-white">
                      {title}
                    </h3>
                  )}
                  {description && (
                    <p id="modal-description" className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                      {description}
                    </p>
                  )}
                </div>
                {showClose && (
                  <button
                    onClick={onClose}
                    className="rounded-lg p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:hover:bg-slate-700 dark:hover:text-slate-300"
                    aria-label="Close modal"
                  >
                    <X className="w-5 h-5" aria-hidden="true" />
                  </button>
                )}
              </div>
            )}

            {/* Content */}
            <div className="max-h-[calc(100dvh-9rem)] overflow-y-auto p-5 sm:max-h-[calc(100dvh-10rem)]">{children}</div>
          </div>
        </div>
      </div>
    </Fragment>
  );
}

interface ConfirmModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  variant?: 'danger' | 'primary';
  loading?: boolean;
}

export function ConfirmModal({
  isOpen,
  onClose,
  onConfirm,
  title,
  message,
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  variant = 'primary',
  loading = false,
}: ConfirmModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} title={title} size="sm">
      <p className="text-slate-600 mb-6">{message}</p>
      <div className="flex justify-end gap-3">
        <Button variant="outline" onClick={onClose} disabled={loading}>
          {cancelText}
        </Button>
        <Button
          variant={variant === 'danger' ? 'danger' : 'primary'}
          onClick={onConfirm}
          loading={loading}
        >
          {confirmText}
        </Button>
      </div>
    </Modal>
  );
}
