export function apiError(error) {
  const body = error.response?.data;
  return body?.errors ? Object.values(body.errors).flat().join(' ') : body?.message || 'Le service est indisponible. Réessayez.';
}

export function downloadText(name, content, type = 'text/plain;charset=utf-8') {
  const url = URL.createObjectURL(new Blob([content], { type }));
  const link = document.createElement('a'); link.href = url; link.download = name;
  document.body.appendChild(link); link.click(); link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
