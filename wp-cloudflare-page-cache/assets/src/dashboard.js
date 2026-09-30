import { createRoot } from '@wordpress/element';

import './dashboard.css';
 
import App from './App.tsx';

const announcementBanner = document.querySelector('.themeisle-sale.notice[data-event-slug="black_friday"]');

if ( announcementBanner ) {
  window.SPCBlackFridayBanner = announcementBanner.innerHTML;
  announcementBanner.remove();
}

// Same for the Themeisle SDK "Connect your AI agent" notice: the dashboard
// hides the admin notices, so the page renders it inside its own layout.
const aiConnectNotice = document.querySelector('[data-ti-ai-notice]');

if ( aiConnectNotice ) {
  window.SPCAiConnectNotice = aiConnectNotice.innerHTML;
  aiConnectNotice.remove();
}

createRoot(document.getElementById('spc-dashboard')).render(<App />);
