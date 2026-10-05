"use client";

import { useEffect, useRef, useState } from "react";

declare global {
  interface Window {
    google?: {
      accounts: {
        id: {
          initialize: (options: {
            client_id: string;
            callback: (response: { credential?: string }) => void;
          }) => void;
          renderButton: (element: HTMLElement, options: {
            theme?: string;
            size?: string;
            shape?: string;
            width?: number;
            text?: string;
          }) => void;
        };
      };
    };
  }
}

type GoogleSignInButtonProps = {
  onCredential: (credential: string) => void;
  disabled?: boolean;
};

export default function GoogleSignInButton({
  onCredential,
  disabled = false,
}: GoogleSignInButtonProps) {
  const buttonRef = useRef<HTMLDivElement>(null);
  const [loadError, setLoadError] = useState("");
  const clientId = process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID;

  useEffect(() => {
    if (!clientId || disabled || !buttonRef.current) return;

    let active = true;
    const render = () => {
      if (!active || !buttonRef.current || !window.google) return;
      window.google.accounts.id.initialize({
        client_id: clientId,
        callback: ({ credential }) => {
          if (credential) onCredential(credential);
        },
      });
      window.google.accounts.id.renderButton(buttonRef.current, {
        theme: "outline",
        size: "large",
        shape: "rectangular",
        text: "continue_with",
        width: buttonRef.current.clientWidth,
      });
    };

    const existingScript = document.querySelector<HTMLScriptElement>(
      'script[src="https://accounts.google.com/gsi/client"]',
    );
    if (window.google) {
      render();
    } else if (existingScript) {
      existingScript.addEventListener("load", render, { once: true });
    } else {
      const script = document.createElement("script");
      script.src = "https://accounts.google.com/gsi/client";
      script.async = true;
      script.defer = true;
      script.onload = render;
      script.onerror = () => setLoadError("Google sign-in could not be loaded. Use your email and password instead.");
      document.head.appendChild(script);
    }

    return () => {
      active = false;
    };
  }, [clientId, disabled, onCredential]);

  if (!clientId) {
    return (
      <button
        className="flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm font-medium text-slate-500"
        disabled
        type="button"
      >
        <GoogleMark />
        Google sign-in not configured
      </button>
    );
  }

  return (
    <div className="w-full">
      <div
        aria-label="Continue with Google"
        className={disabled ? "pointer-events-none opacity-60" : "min-h-11"}
        ref={buttonRef}
      />
      {loadError && <p className="mt-2 text-center text-xs text-rose-700" role="alert">{loadError}</p>}
    </div>
  );
}

function GoogleMark() {
  return (
    <svg aria-hidden="true" className="h-4 w-4" viewBox="0 0 48 48">
      <path fill="#4285F4" d="M43.6 24.5c0-1.4-.1-2.8-.4-4.1H24v7.8h11a9.4 9.4 0 0 1-4.1 6.2v5.1h6.6c3.9-3.6 6.1-8.8 6.1-15Z" />
      <path fill="#34A853" d="M24 44c5.5 0 10.1-1.8 13.5-4.9l-6.6-5.1c-1.8 1.2-4 2-6.9 2-5.3 0-9.8-3.6-11.4-8.4H5.8v5.3A20 20 0 0 0 24 44Z" />
      <path fill="#FBBC05" d="M12.6 27.6a12 12 0 0 1 0-7.2v-5.3H5.8a20 20 0 0 0 0 17.8l6.8-5.3Z" />
      <path fill="#EA4335" d="M24 12c3 0 5.7 1 7.8 3l5.8-5.8A19.5 19.5 0 0 0 24 4 20 20 0 0 0 5.8 15.1l6.8 5.3C14.2 15.6 18.7 12 24 12Z" />
    </svg>
  );
}
