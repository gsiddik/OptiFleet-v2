import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { LoadingState } from '../components/States';

/**
 * Landing page of "Sign in with OptiNexus". The backend redirects here with a one-time ticket (valid for
 * a minute); it is traded for a session at once, so no access token ever appears in a URL.
 */
export function SsoCallbackPage() {
  const { t } = useTranslation();
  const { completeSsoLogin } = useAuth();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [ticket] = useState(() => params.get('ticket'));
  const [exchangeFailed, setExchangeFailed] = useState(false);
  const failed = exchangeFailed || !ticket;
  // A ticket works once: React StrictMode runs effects twice in development, the second run must not spend it again.
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    if (!ticket) return;
    // Drop the ticket from the address bar and history before anything else happens.
    window.history.replaceState(null, '', window.location.pathname);

    completeSsoLogin(ticket)
      .then(() => navigate('/', { replace: true }))
      .catch(() => setExchangeFailed(true));
  }, [ticket, completeSsoLogin, navigate]);

  if (failed) {
    return (
      <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: '#f3f4f6' }}>
        <div style={{ background: '#fff', padding: 32, borderRadius: 12, width: 360, boxShadow: '0 4px 20px rgba(0,0,0,0.08)' }}>
          <div style={{ background: '#fef2f2', color: '#b91c1c', padding: 10, borderRadius: 6, marginBottom: 16, fontSize: 13 }}>
            {t('auth.ssoErrors.ticketInvalid')}
          </div>
          <Link to="/login">{t('auth.actions.backToSignIn')}</Link>
        </div>
      </div>
    );
  }

  return <LoadingState label={t('auth.help.completingSignIn')} />;
}
