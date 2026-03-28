import { forwardRef, type InputHTMLAttributes, type ReactNode, useState, useEffect } from 'react';
import { clsx } from 'clsx';
import { CheckCircle2, AlertCircle } from 'lucide-react';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
  hint?: string;
  helper?: string; // alias for hint
  leftIcon?: ReactNode;
  rightIcon?: ReactNode;
  fullWidth?: boolean;
  showValidation?: boolean; // Show success/error icons
  success?: boolean; // Show success state
}

const Input = forwardRef<HTMLInputElement, InputProps>(
  (
    {
      className,
      label,
      error,
      hint,
      helper,
      leftIcon,
      rightIcon,
      fullWidth = true,
      showValidation = false,
      success = false,
      id,
      ...props
    },
    ref
  ) => {
    const hintText = hint || helper;
    const inputId = id || props.name;
    const [shouldShake, setShouldShake] = useState(false);

    // Trigger shake animation when error appears
    useEffect(() => {
      if (error) {
        setShouldShake(true);
        const timer = setTimeout(() => setShouldShake(false), 500);
        return () => clearTimeout(timer);
      }
    }, [error]);

    const hasValidationIcon = showValidation && (error || success);
    const validationIcon = error ? (
      <AlertCircle className="w-5 h-5 text-red-500" aria-hidden="true" />
    ) : success ? (
      <CheckCircle2 className="w-5 h-5 text-green-500" aria-hidden="true" />
    ) : null;

    return (
      <div className={clsx(fullWidth && 'w-full')}>
        {label && (
          <label
            htmlFor={inputId}
            className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1"
          >
            {label}
            {props.required && (
              <span className="text-red-500 dark:text-red-400 ml-1" aria-label="required">
                *
              </span>
            )}
          </label>
        )}

        <div className="relative">
          {leftIcon && (
            <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400 dark:text-slate-500">
              {leftIcon}
            </div>
          )}

          <input
            ref={ref}
            id={inputId}
            className={clsx(
              'block rounded-lg border bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500',
              'focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 dark:focus:ring-primary-400',
              'disabled:bg-slate-50 dark:disabled:bg-slate-900 disabled:text-slate-500 dark:disabled:text-slate-600 disabled:cursor-not-allowed',
              'transition-all duration-200',
              fullWidth && 'w-full',
              leftIcon ? 'pl-10' : 'pl-3',
              rightIcon || hasValidationIcon ? 'pr-10' : 'pr-3',
              'py-2 text-sm',
              error
                ? 'border-red-300 dark:border-red-800 focus:ring-red-500 focus:border-red-500'
                : success
                ? 'border-green-300 dark:border-green-800 focus:ring-green-500 focus:border-green-500'
                : 'border-slate-300 dark:border-slate-700',
              shouldShake && 'animate-shake',
              className
            )}
            aria-invalid={error ? 'true' : 'false'}
            aria-describedby={
              error ? `${inputId}-error` : hintText ? `${inputId}-hint` : undefined
            }
            {...props}
          />

          {(rightIcon || hasValidationIcon) && (
            <div className="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
              {hasValidationIcon ? validationIcon : rightIcon}
            </div>
          )}
        </div>

        {error && (
          <p
            id={`${inputId}-error`}
            className="mt-1 text-sm text-red-600 dark:text-red-400 flex items-start gap-1"
            role="alert"
          >
            <AlertCircle className="w-4 h-4 flex-shrink-0 mt-0.5" aria-hidden="true" />
            <span>{error}</span>
          </p>
        )}

        {hintText && !error && (
          <p
            id={`${inputId}-hint`}
            className="mt-1 text-sm text-slate-500 dark:text-slate-400"
          >
            {hintText}
          </p>
        )}
      </div>
    );
  }
);

Input.displayName = 'Input';

export default Input;

// Textarea component
export interface TextareaProps
  extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  error?: string;
  hint?: string;
  fullWidth?: boolean;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, label, error, hint, fullWidth = true, id, ...props }, ref) => {
    const inputId = id || props.name;

    return (
      <div className={clsx(fullWidth && 'w-full')}>
        {label && (
          <label
            htmlFor={inputId}
            className="block text-sm font-medium text-slate-700 mb-1"
          >
            {label}
            {props.required && <span className="text-red-500 ml-1">*</span>}
          </label>
        )}

        <textarea
          ref={ref}
          id={inputId}
          className={clsx(
            'block rounded-lg border bg-white text-slate-900 dark:text-slate-100 placeholder-slate-400',
            'focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500',
            'disabled:bg-slate-50 disabled:text-slate-500 disabled:cursor-not-allowed',
            'px-3 py-2 text-sm min-h-[80px]',
            fullWidth && 'w-full',
            error
              ? 'border-red-300 focus:ring-red-500 focus:border-red-500'
              : 'border-slate-300',
            className
          )}
          {...props}
        />

        {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
        {hint && !error && <p className="mt-1 text-sm text-slate-500">{hint}</p>}
      </div>
    );
  }
);

Textarea.displayName = 'Textarea';
