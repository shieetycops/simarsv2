// Type declarations for Cloudflare Turnstile
interface TurnstileOptions {
  sitekey: string;
  callback?: (token: string) => void;
  "expired-callback"?: () => void;
  "error-callback"?: () => void;
  theme?: "light" | "dark" | "auto";
  size?: "normal" | "compact";
  tabindex?: number;
  "response-field"?: boolean;
  "response-field-name"?: string;
}

interface Turnstile {
  render: (container: HTMLElement, options: TurnstileOptions) => string;
  reset: (widgetId: string) => void;
  remove: (widgetId: string) => void;
  getResponse: (widgetId: string) => string | undefined;
}

interface Window {
  turnstile?: Turnstile;
}