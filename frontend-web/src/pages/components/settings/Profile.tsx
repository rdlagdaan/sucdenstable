import { useEffect, useMemo, useRef, useState } from 'react';
import napi from '../../../utils/axiosnapi';
import Swal from 'sweetalert2';

type ProfileData = {
  id: number;
  username: string;
  email_address: string;
  first_name: string;
  middle_name: string;
  last_name: string;
  photo_url?: string | null;
};

const required = (v: string) => v.trim().length > 0;

export default function Profile() {
  // ----- Basic profile form -----
  const [form, setForm] = useState<ProfileData | null>(null);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  // ----- Password form (separate submit) -----
  const [oldPassword, setOldPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newPassword2, setNewPassword2] = useState('');
  const [pwdSaving, setPwdSaving] = useState(false);
  const [pwdErrors, setPwdErrors] = useState<Record<string, string>>({});

  // ----- Photo upload (separate submit) -----
  const [photoFile, setPhotoFile] = useState<File | null>(null);
  const [photoPreview, setPhotoPreview] = useState<string | null>(null);
  const [photoSaving, setPhotoSaving] = useState(false);
  const fileRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const res = await napi.get('/user/profile');
        setForm(res.data);
        //if (res.data?.photo_url) setPhotoPreview(res.data.photo_url);
        if (res.data?.photo_url) {
        // always use current origin to preserve :3001
        const finalUrl = `${window.location.origin}/api/user/profile/photo`;
        setPhotoPreview(`${finalUrl}?t=${Date.now()}`);
        }



      } catch (e) {
        console.error('Failed to load profile', e);
        Swal.fire('Error', 'Failed to load profile', 'error');
      }
    })();
  }, []);

  const onChange = <K extends keyof ProfileData,>(key: K, v: ProfileData[K]) => {
    if (!form) return;
    setForm({ ...form, [key]: v });
  };

  // ---------- Frontend validation for basic form ----------
  const basicInvalid = useMemo(() => {
    if (!form) return true;
    const nextErrors: Record<string, string> = {};
    if (!required(form.username)) nextErrors.username = 'Username is required';
    if (!required(form.email_address)) nextErrors.email_address = 'Email is required';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email_address))
      nextErrors.email_address = 'Email format is invalid';
    if (!required(form.first_name)) nextErrors.first_name = 'Firstname is required';
    if (!required(form.last_name)) nextErrors.last_name = 'Lastname is required';
    setErrors(nextErrors);
    return Object.keys(nextErrors).length > 0;
  }, [form]);

  // Nice error extractor for Laravel responses
  const laravelMsg = (e: any) => {
    if (e?.response?.data?.errors) {
      const errs = e.response.data.errors;
      return Object.keys(errs).map(k => `${k}: ${errs[k].join(', ')}`).join('\n');
    }
    const code = e?.response?.status ? ` (HTTP ${e.response.status})` : '';
    return (e?.response?.data?.message || 'Request failed') + code;
  };

  // BASIC INFO SAVE with SweetAlert confirm
  const submitBasic = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form) return;
    if (basicInvalid) {
      Swal.fire('Check fields', 'Please fix the highlighted fields.', 'warning');
      return;
    }

    const ok = await Swal.fire({
      title: 'Save changes?',
      text: 'Your profile information will be updated.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Save',
    }).then(r => r.isConfirmed);
    if (!ok) return;

    setSaving(true);
    try {
      await napi.put('/user/profile', {
        username: form.username,
        email_address: form.email_address,
        first_name: form.first_name,
        middle_name: form.middle_name ?? '',
        last_name: form.last_name,
      });
      Swal.fire('Saved', 'Profile updated', 'success');
    } catch (e: any) {
      Swal.fire('Update failed', laravelMsg(e), 'error');
    } finally {
      setSaving(false);
    }
  };

  // ---------- Password update: separate button; requires old password ----------
  const pwdInvalid = useMemo(() => {
    const next: Record<string, string> = {};
    if (!required(oldPassword)) next.old = 'Current password is required';
    if (!required(newPassword)) next.new = 'New password is required';
    else if (newPassword.length < 8) next.new = 'Password must be at least 8 characters';
    if (newPassword !== newPassword2) next.conf = 'Passwords do not match';
    setPwdErrors(next);
    return Object.keys(next).length > 0;
  }, [oldPassword, newPassword, newPassword2]);

  // PASSWORD UPDATE with SweetAlert confirm
  const submitPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (pwdInvalid) {
      Swal.fire('Check fields', 'Please fix password fields.', 'warning');
      return;
    }

    const ok = await Swal.fire({
      title: 'Update password?',
      text: 'Your current password will be verified first.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Update',
    }).then(r => r.isConfirmed);
    if (!ok) return;

    setPwdSaving(true);
    try {
      await napi.post('/user/profile/password', {
        old_password: oldPassword,
        new_password: newPassword,
        new_password_confirmation: newPassword2,
      });
      Swal.fire('Updated', 'Password updated', 'success');
      setOldPassword('');
      setNewPassword('');
      setNewPassword2('');
      setPwdErrors({});
    } catch (e: any) {
      Swal.fire('Password update failed', laravelMsg(e), 'error');
    } finally {
      setPwdSaving(false);
    }
  };

  // ---------- Photo upload ----------
  const onPickPhoto = (file: File | null) => {
    if (!file) return;
    const maxMB = 2;
    if (file.size > maxMB * 1024 * 1024) {
      Swal.fire('Too large', `File too large. Max ${maxMB}MB.`, 'warning');
      return;
    }
    if (!['image/jpeg', 'image/jpg', 'image/png'].includes(file.type)) {
      Swal.fire('Invalid type', 'Only JPG or PNG allowed.', 'warning');
      return;
    }
    setPhotoFile(file);
    const reader = new FileReader();
    reader.onload = () => setPhotoPreview(String(reader.result));
    reader.readAsDataURL(file);
  };

  // PHOTO UPLOAD with SweetAlert confirm + cache-busting preview
  const submitPhoto = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!photoFile) {
      Swal.fire('Choose a file', 'Please choose a photo first.', 'info');
      return;
    }

    const ok = await Swal.fire({
      title: 'Upload this photo?',
      text: 'This will replace your current profile picture.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Upload',
    }).then(r => r.isConfirmed);
    if (!ok) return;

    setPhotoSaving(true);
    try {
      const fd = new FormData();
      fd.append('photo', photoFile);
      await napi.post('/user/profile/photo', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      
      const finalUrl = `${window.location.origin}/api/user/profile/photo`;
      setPhotoPreview(`${finalUrl}?t=${Date.now()}`); // cache-bust

      
      setPhotoFile(null);
      if (fileRef.current) fileRef.current.value = '';
      Swal.fire('Uploaded', 'Photo updated', 'success');
    } catch (e: any) {
      Swal.fire('Photo upload failed', laravelMsg(e), 'error');
    } finally {
      setPhotoSaving(false);
    }
  };

  if (!form) return <div className="p-4">Loading profile...</div>;

  return (
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      {/* Left: Photo */}
      <div className="bg-white rounded-xl shadow p-6">
        <h3 className="text-lg font-semibold mb-4">Employee Photo</h3>
        <div className="flex flex-col items-center gap-4">
          <div className="w-40 h-40 rounded-full overflow-hidden border">
            {photoPreview ? (
              <img src={photoPreview} alt="Profile" className="w-full h-full object-cover" />
            ) : (
              <div className="w-full h-full grid place-content-center text-sm text-gray-400">
                No photo
              </div>
            )}
          </div>
          <form onSubmit={submitPhoto} className="w-full">
            <input
              ref={fileRef}
              type="file"
              accept="image/png,image/jpeg"
              onChange={(e) => onPickPhoto(e.target.files?.[0] ?? null)}
              className="block w-full text-sm mb-3"
            />
            <button
              type="submit"
              disabled={photoSaving}
              className="w-full bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-60"
            >
              {photoSaving ? 'Uploading…' : 'Upload Photo'}
            </button>
          </form>
          <p className="text-xs text-gray-500">JPG or PNG, max 2MB.</p>
        </div>
      </div>

      {/* Middle: Basic info */}
      <div className="bg-white rounded-xl shadow p-6 lg:col-span-2">
        <h3 className="text-lg font-semibold mb-4">Account Information</h3>
        <form onSubmit={submitBasic} className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium">Username</label>
              <input
                className={`mt-1 w-full border rounded px-3 py-2 ${errors.username ? 'border-red-500' : 'border-gray-300'}`}
                value={form.username}
                onChange={(e) => onChange('username', e.target.value)}
                required
              />
              {errors.username && <p className="text-xs text-red-600 mt-1">{errors.username}</p>}
            </div>
            <div>
              <label className="block text-sm font-medium">Email Address</label>
              <input
                type="email"
                className={`mt-1 w-full border rounded px-3 py-2 ${errors.email_address ? 'border-red-500' : 'border-gray-300'}`}
                value={form.email_address}
                onChange={(e) => onChange('email_address', e.target.value)}
                required
              />
              {errors.email_address && <p className="text-xs text-red-600 mt-1">{errors.email_address}</p>}
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium">Firstname</label>
              <input
                className={`mt-1 w-full border rounded px-3 py-2 ${errors.first_name ? 'border-red-500' : 'border-gray-300'}`}
                value={form.first_name}
                onChange={(e) => onChange('first_name', e.target.value)}
                required
              />
              {errors.first_name && <p className="text-xs text-red-600 mt-1">{errors.first_name}</p>}
            </div>
            <div>
              <label className="block text-sm font-medium">Middlename</label>
              <input
                className="mt-1 w-full border rounded px-3 py-2 border-gray-300"
                value={form.middle_name ?? ''}
                onChange={(e) => onChange('middle_name', e.target.value)}
              />
            </div>
            <div>
              <label className="block text-sm font-medium">Lastname</label>
              <input
                className={`mt-1 w-full border rounded px-3 py-2 ${errors.last_name ? 'border-red-500' : 'border-gray-300'}`}
                value={form.last_name}
                onChange={(e) => onChange('last_name', e.target.value)}
                required
              />
              {errors.last_name && <p className="text-xs text-red-600 mt-1">{errors.last_name}</p>}
            </div>
          </div>

          <div className="pt-2">
            <button
              type="submit"
              disabled={saving || basicInvalid}
              className="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-60"
            >
              {saving ? 'Saving…' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>

      {/* Bottom row: Password panel */}
      <div className="bg-white rounded-xl shadow p-6 lg:col-span-3">
        <h3 className="text-lg font-semibold mb-4">Change Password</h3>
        <form onSubmit={submitPassword} className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label className="block text-sm font-medium">Current Password</label>
            <input
              type="password"
              className={`mt-1 w-full border rounded px-3 py-2 ${pwdErrors.old ? 'border-red-500' : 'border-gray-300'}`}
              value={oldPassword}
              onChange={(e) => setOldPassword(e.target.value)}
              required
            />
            {pwdErrors.old && <p className="text-xs text-red-600 mt-1">{pwdErrors.old}</p>}
          </div>
          <div>
            <label className="block text-sm font-medium">New Password</label>
            <input
              type="password"
              className={`mt-1 w-full border rounded px-3 py-2 ${pwdErrors.new ? 'border-red-500' : 'border-gray-300'}`}
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              required
              minLength={8}
            />
            {pwdErrors.new && <p className="text-xs text-red-600 mt-1">{pwdErrors.new}</p>}
          </div>
          <div>
            <label className="block text-sm font-medium">Confirm New Password</label>
            <input
              type="password"
              className={`mt-1 w-full border rounded px-3 py-2 ${pwdErrors.conf ? 'border-red-500' : 'border-gray-300'}`}
              value={newPassword2}
              onChange={(e) => setNewPassword2(e.target.value)}
              required
            />
            {pwdErrors.conf && <p className="text-xs text-red-600 mt-1">{pwdErrors.conf}</p>}
          </div>
          <div className="md:col-span-3">
            <button
              type="submit"
              disabled={pwdSaving || pwdInvalid}
              className="bg-slate-700 text-white px-4 py-2 rounded hover:bg-slate-800 disabled:opacity-60"
            >
              {pwdSaving ? 'Updating…' : 'Update Password'}
            </button>
          </div>
        </form>
        <p className="text-xs text-gray-500 mt-2">Your current password must be correct before you can set a new one.</p>
      </div>
    </div>
  );
}
