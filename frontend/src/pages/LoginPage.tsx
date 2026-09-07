import { useState, type FormEvent } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { inputStyle } from '../components/FormField';

export function LoginPage() {
  const { user, login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

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
      setError((err as { message?: string }).message ?? 'Login failed.');
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
        <h1 style={{ fontSize: 22, marginBottom: 4 }}>OptiFleet</h1>
        <p style={{ color: '#6b7280', marginTop: 0, marginBottom: 24, fontSize: 13 }}>Sign in to continue</p>

        {error && (
          <div style={{ background: '#fef2f2', color: '#b91c1c', padding: 10, borderRadius: 6, marginBottom: 16, fontSize: 13 }}>
            {error}
          </div>
        )}

        <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 4 }}>Email</label>
        <input
          type="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          style={{ ...inputStyle, marginBottom: 14 }}
          autoFocus
        />

        <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 4 }}>Password</label>
        <input
          type="password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          style={{ ...inputStyle, marginBottom: 20 }}
        />

        <button type="submit" disabled={submitting} className="btn-primary" style={{ width: '100%' }}>
          {submitting ? 'Signing in…' : 'Sign In'}
        </button>
      </form>
    </div>
  );
}
