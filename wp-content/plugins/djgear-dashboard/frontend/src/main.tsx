import React from 'react';
import ReactDOM from 'react-dom/client';
import { App } from './App';
import './styles/theme.css';

const mountNode = document.getElementById('djgear-dashboard-root');
const config = window.DJGEAR_DASHBOARD_CONFIG;

if (mountNode && config) {
  ReactDOM.createRoot(mountNode).render(
    <React.StrictMode>
      <App config={config} />
    </React.StrictMode>
  );
}
