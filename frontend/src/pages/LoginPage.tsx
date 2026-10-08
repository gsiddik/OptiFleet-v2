import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Navigate, useSearchParams } from 'react-router-dom';
import { API_BASE_URL, apiClient } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { ssoErrorKey } from '../auth/ssoErrors';
import { inputStyle } from '../components/FormField';
import { LanguageSelector } from '../components/LanguageSelector';

export function LoginPage() {
  const { t } = useTranslation();
  const { user, login } = useAuth();
  const [params] = useSearchParams();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const ssoErrorMessageKey = ssoErrorKey(params.get('sso_error'));
  const [error, setError] = useState<string | null>(ssoErrorMessageKey ? t(ssoErrorMessageKey) : null);
  const [submitting, setSubmitting] = useState(false);
  const [ssoEnabled, setSsoEnabled] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiClient
      .get<{ data: { enabled: boolean } }>('/auth/sso/status')
      .then((res) => {
        if (!cancelled) setSsoEnabled(res.data.data.enabled === true);
      })
      .catch(() => {
        // Password login keeps working when the status cannot be read.
      });
    return () => {
      cancelled = true;
    };
  }, []);

  if (user) {
    return <Navigate to={user.scope === 'platform' ? '/platform/dashboard' : '/app/dashboard'} replace />;
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await login(email, password);
    } catch (err) {
      setError((err as { message?: string }).message ?? t('auth.errors.loginFailed'));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: '#f3f4f6' }}>
      <form
        onSubmit={handleSubmit}
        style={{ background: '#fff', padding: 32, borderRadius: 12, width: 360, boxShadow: '0 4px 20px rgba(0,0,0,0.08)' }}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <h1 style={{ fontSize: 22, marginBottom: 4 }}>{t('auth.titles.optiFleet')}</h1>
          <LanguageSelector compact />
        </div>
        <p style={{ color: '#6b7280', marginTop: 0, marginBottom: 24, fontSize: 13 }}>{t('auth.help.signInToContinue')}</p>

        {error && (
          <div style={{ background: '#fef2f2', color: '#b91c1c', padding: 10, borderRadius: 6, marginBottom: 16, fontSize: 13 }}>
            {error}
          </div>
        )}

        <label htmlFor="login-email" style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 4 }}>{t('common.fields.email')}</label>
        <input
          id="login-email"
          type="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          style={{ ...inputStyle, marginBottom: 14 }}
          autoFocus
        />

        <label htmlFor="login-password" style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 4 }}>{t('common.fields.password')}</label>
        <input
          id="login-password"
          type="password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          style={{ ...inputStyle, marginBottom: 20 }}
        />

        <button type="submit" disabled={submitting} className="btn-primary" style={{ width: '100%' }}>
          {submitting ? t('auth.actions.signingIn') : t('auth.actions.signIn')}
        </button>

        {ssoEnabled && (
          <>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, margin: '18px 0', color: '#9ca3af', fontSize: 12 }}>
              <span style={{ flex: 1, height: 1, background: '#e5e7eb' }} />
              {t('auth.help.or')}
              <span style={{ flex: 1, height: 1, background: '#e5e7eb' }} />
            </div>
            <button
              type="button"
              className="btn-secondary"
              style={{ width: '100%' }}
              onClick={() => window.location.assign(`${API_BASE_URL}/auth/sso/redirect`)}
            >
              {t('auth.actions.signInWithOptinexus')}
            </button>
          </>
        )}
      </form>
    </div>
  );
}
