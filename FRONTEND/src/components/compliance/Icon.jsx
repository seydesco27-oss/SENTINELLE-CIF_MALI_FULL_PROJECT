const paths = {
  dashboard: <><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></>,
  alert: <><path d="m12 3 10 18H2L12 3Z" /><path d="M12 9v4m0 4h.01" /></>,
  investigation: <><path d="M3 7V5a2 2 0 0 1 2-2h5l3 3h6a2 2 0 0 1 2 2v3M3 7h12M3 7v12a2 2 0 0 0 2 2h7" /><circle cx="17" cy="16" r="4" /><path d="m20 19 2 3" /></>,
  centif: <><path d="M14 3H5v18h14V8l-5-5Zm0 0v5h5M8 12h8m-8 4h5" /></>,
  clients: <><circle cx="9" cy="8" r="3" /><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 5" /></>,
  accounts: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h3" /></>,
  transactions: <><path d="M4 7h16m-4-4 4 4-4 4M20 17H4m4-4-4 4 4 4" /></>,
  screening: <><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z" /><path d="m8 12 3 3 5-6" /></>,
  risk: <><path d="M4 20h17M7 16v-5m5 5V4m5 12V8" /></>,
  ml: <><path d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5" /></>,
  reports: <><path d="M5 3h14v18H5zM8 8h8m-8 4h8m-8 4h5" /></>,
  audit: <><path d="M8 5H4v16h16V5h-4M8 3h8v5H8zM8 13h8m-8 4h5" /></>,
  network: <><circle cx="12" cy="5" r="2" /><circle cx="5" cy="19" r="2" /><circle cx="19" cy="19" r="2" /><path d="M12 7v5m-7 5v-5h14v5" /></>,
  settings: <><path d="M4 7h16M4 17h16" /><circle cx="9" cy="7" r="3" /><circle cx="15" cy="17" r="3" /></>,
  search: <><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 5 5" /></>,
  arrow: <path d="M4 12h16m-6-6 6 6-6 6" />,
  arrowUp: <path d="M6 18 18 6M6 6h12v12" />,
  chevron: <path d="m9 5 7 7-7 7" />,
  refresh: <><path d="M20 7v5h-5M4 17v-5h5" /><path d="M5 8a8 8 0 0 1 13-3l2 3M4 16l2 3a8 8 0 0 0 13-3" /></>,
  calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M7 3v4m10-4v4M3 11h18" /></>,
  clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
  logout: <><path d="M9 4H4v16h5M10 12h11m-4-4 4 4-4 4" /></>,
  menu: <path d="M4 6h16M4 12h16M4 18h16" />,
  close: <path d="m6 6 12 12M6 18 18 6" />,
  check: <path d="m5 12 4 4L19 6" />,
  download: <><path d="M12 3v12m-4-4 4 4 4-4M4 16v5h16v-5" /></>,
};

export default function Icon({ name, size = 18, ...props }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>{paths[name] || paths.dashboard}</svg>;
}
