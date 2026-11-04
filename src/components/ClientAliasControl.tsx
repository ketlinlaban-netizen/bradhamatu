import { useState, type FormEvent } from "react";
import { Check, Edit3, Loader2, Trash2, X } from "lucide-react";
import { useAuth } from "../auth/AuthContext";
import { clearClientAlias, saveClientAlias } from "../services/ClientAliasService";
import type { RouterClient } from "../types";

const MAX_ALIAS_LENGTH = 80;

export function ClientAliasControl({
  client,
  onSaved,
}: {
  client: RouterClient;
  onSaved: (updated: RouterClient) => void;
}) {
  const { user } = useAuth();
  const [editing, setEditing] = useState(false);
  const [alias, setAlias] = useState(client.alias ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const canEdit = client.aliasEditable === true
    && (user?.role === "admin" || user?.role === "super_admin");

  if (!canEdit) return null;

  const beginEditing = () => {
    setAlias(client.alias ?? "");
    setError(null);
    setEditing(true);
  };

  const cancelEditing = () => {
    setAlias(client.alias ?? "");
    setError(null);
    setEditing(false);
  };

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const trimmedAlias = alias.trim();
    if (!trimmedAlias) {
      setError("Enter a device name.");
      return;
    }
    if (trimmedAlias.length > MAX_ALIAS_LENGTH) {
      setError(`Device names must be ${MAX_ALIAS_LENGTH} characters or fewer.`);
      return;
    }

    setSaving(true);
    setError(null);
    try {
      onSaved(await saveClientAlias(client, trimmedAlias));
      setEditing(false);
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Could not save this device name.");
    } finally {
      setSaving(false);
    }
  };

  const clear = async () => {
    setSaving(true);
    setError(null);
    try {
      onSaved(await clearClientAlias(client));
      setAlias("");
      setEditing(false);
    } catch (clearError) {
      setError(clearError instanceof Error ? clearError.message : "Could not clear this device name.");
    } finally {
      setSaving(false);
    }
  };

  if (!editing) {
    return (
      <button
        type="button"
        onClick={beginEditing}
        className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs text-noc-accent hover:bg-noc-hover"
        aria-label={`${client.alias ? "Edit" : "Rename"} ${client.displayName || client.hostname || "device"}`}
      >
        <Edit3 className="h-3 w-3" />
        {client.alias ? "Edit name" : "Rename"}
      </button>
    );
  }

  return (
    <form onSubmit={save} className="mt-2 flex min-w-52 flex-col gap-2" aria-label="Edit device name">
      <label className="sr-only" htmlFor={`alias-${client.id}`}>Friendly device name</label>
      <input
        id={`alias-${client.id}`}
        autoFocus
        maxLength={MAX_ALIAS_LENGTH}
        value={alias}
        onChange={(event) => setAlias(event.target.value)}
        disabled={saving}
        placeholder="Friendly device name"
        className="input-field px-2 py-1 text-xs"
      />
      {error && <p role="alert" className="max-w-64 text-xs text-red-300">{error}</p>}
      <div className="flex flex-wrap items-center gap-1">
        <button
          type="submit"
          disabled={saving || !alias.trim()}
          className="inline-flex items-center gap-1 rounded bg-cyan-600 px-2 py-1 text-xs text-white disabled:opacity-50"
        >
          {saving ? <Loader2 className="h-3 w-3 animate-spin" /> : <Check className="h-3 w-3" />}
          Save
        </button>
        {client.alias && (
          <button
            type="button"
            disabled={saving}
            onClick={() => void clear()}
            className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs text-red-300 hover:bg-red-500/10 disabled:opacity-50"
          >
            <Trash2 className="h-3 w-3" />
            Clear
          </button>
        )}
        <button
          type="button"
          disabled={saving}
          onClick={cancelEditing}
          className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs text-slate-400 hover:bg-noc-hover disabled:opacity-50"
        >
          <X className="h-3 w-3" />
          Cancel
        </button>
      </div>
    </form>
  );
}
