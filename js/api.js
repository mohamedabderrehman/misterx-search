// API Configuration
// API base URL - updated to work from root directory
const API_BASE_URL = window.location.origin + '/api';

// Ensure Logger is available (load utils.js before api.js)
if (typeof Logger === 'undefined') {
    window.Logger = {
        log: function() { if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') console.log.apply(console, arguments); },
        error: function() { if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') console.error.apply(console, arguments); },
        warn: function() { if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') console.warn.apply(console, arguments); }
    };
}

// Helper function to get auth token
function getAuthToken() {
  return localStorage.getItem('authToken');
}

// Helper function to set auth token
function setAuthToken(token) {
  localStorage.setItem('authToken', token);
}

// Helper function to remove auth token
function removeAuthToken() {
  localStorage.removeItem('authToken');
}

// Helper function to get auth headers
function getAuthHeaders() {
  const token = getAuthToken();
  const headers = {};
  
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }
  
  return headers;
}

// API Request helper
async function apiRequest(endpoint, options = {}) {
  // Add .php extension if not present
  if (!endpoint.endsWith('.php') && !endpoint.includes('?')) {
    endpoint += '.php';
  } else if (endpoint.includes('?') && !endpoint.split('?')[0].endsWith('.php')) {
    const [path, query] = endpoint.split('?');
    endpoint = path + '.php' + (query ? '?' + query : '');
  }
  
  const url = `${API_BASE_URL}${endpoint}`;
  
  // Extract method and body from options
  const method = options.method || 'GET';
  const body = options.body;
  
  // Build config object
  const config = {
    method: method,
    headers: {
      'Content-Type': 'application/json',
      ...getAuthHeaders() // This will add Authorization header if token exists
    }
  };

  // Only add body for POST, PUT, PATCH methods
  if (body && ['POST', 'PUT', 'PATCH'].includes(method.toUpperCase())) {
    if (typeof body === 'object') {
      config.body = JSON.stringify(body);
    } else {
      config.body = body;
    }
  }

  try {
    Logger.log('Making request to:', url, 'Method:', method, 'Config:', config);
    const response = await fetch(url, config);
    
    // Get response text first
    const responseText = await response.text();
    Logger.log('Response status:', response.status);
    Logger.log('Response text:', responseText.substring(0, 500));
    
    // Check if response is HTML (error page)
    const contentType = response.headers.get('content-type');
    if (contentType && contentType.includes('text/html')) {
      Logger.error('HTML Response (Error):', responseText.substring(0, 500));
      throw new Error('Server returned HTML instead of JSON. Check PHP errors.');
    }
    
    // Parse JSON
    let data;
    try {
      data = JSON.parse(responseText);
    } catch (e) {
      Logger.error('Failed to parse JSON:', responseText.substring(0, 500));
      throw new Error(`Invalid JSON response: ${responseText.substring(0, 100)}`);
    }
    
    // Check if request was successful
    if (!response.ok && response.status !== 201) {
      Logger.error('Error response:', data);
      const error = new Error(data.message || `HTTP ${response.status}: Request failed`);
      error.status = response.status; // Add status code to error object
      error.response = data; // Add full response to error object
      throw error;
    }
    
    return data;
  } catch (error) {
    Logger.error('API Error:', error);
    Logger.error('URL:', url);
    Logger.error('Method:', method);
    Logger.error('Config:', config);
    throw error;
  }
}

// Authentication API
const authAPI = {
  register: async (email, username, password, recaptchaToken = '') => {
    return await apiRequest('/auth/register', {
      method: 'POST',
      body: { email, username, password, recaptcha_token: recaptchaToken }
    });
  },

  login: async (emailOrUsername, password, rememberMe = false, recaptchaToken = '') => {
    const response = await apiRequest('/auth/login', {
      method: 'POST',
      body: { emailOrUsername, password, rememberMe, recaptcha_token: recaptchaToken }
    });
    
    if (response.success && response.data.token) {
      setAuthToken(response.data.token);
    }
    
    return response;
  },

  logout: () => {
    Logger.log('Logging out...');
    removeAuthToken();
    Logger.log('Token removed, redirecting to signin');
    // Small delay before redirect
    setTimeout(() => {
      window.location.href = '/Login';
    }, 100);
  },

  getCurrentUser: async () => {
    return await apiRequest('/auth/me', {
      method: 'GET'
    });
  }
};

// User API
const userAPI = {
  getProfile: async () => {
    return await apiRequest('/user/profile', {
      method: 'GET'
    });
  },

  updateProfile: async (email, username) => {
    return await apiRequest('/user/profile', {
      method: 'PUT',
      body: { email, username }
    });
  },

  changePassword: async (currentPassword, newPassword, confirmNewPassword) => {
    return await apiRequest('/user/password', {
      method: 'PUT',
      body: { currentPassword, newPassword, confirmNewPassword }
    });
  }
};

// Search API
const searchAPI = {
  // Legacy synchronous search (kept for backward compatibility)
  search: async (query, searchType = 'all', dateRange = 'all', removeDuplicates = false, searchAfter = null, totalLoaded = 0) => {
    return await apiRequest('/search/index', {
      method: 'POST',
      body: { 
        query, 
        search_type: searchType, 
        date_range: dateRange, 
        remove_duplicates: removeDuplicates,
        search_after: searchAfter,
        total_loaded: totalLoaded
      }
    });
  },

  // Streaming search for unlimited users (Server-Sent Events)
  searchStream: (query, removeDuplicates = false, onBatch, onComplete, onError) => {
    const token = getAuthToken();
    const removeDuplicatesParam = removeDuplicates ? 'true' : 'false';
    // EventSource doesn't support custom headers, so we pass token in query parameter
    const url = `${API_BASE_URL}/search/stream.php?query=${encodeURIComponent(query)}&remove_duplicates=${removeDuplicatesParam}${token ? '&token=' + encodeURIComponent(token) : ''}`;
    
    const eventSource = new EventSource(url, {
      withCredentials: true
    });
    
    let allResults = [];
    let totalResults = 0;
    
    eventSource.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        
        if (data.type === 'start') {
          // Search started
          if (onBatch) onBatch([], 0, 0, 'start');
        } else if (data.type === 'batch') {
          // New batch received
          allResults = allResults.concat(data.batch);
          totalResults = data.totalResults;
          
          if (onBatch) {
            onBatch(data.batch, data.batchNumber, data.totalResults, 'batch');
          }
        } else if (data.type === 'complete') {
          // Search completed
          eventSource.close();
          if (onComplete) {
            onComplete(allResults, totalResults);
          }
        } else if (data.type === 'error') {
          // Error occurred
          eventSource.close();
          if (onError) {
            onError(data.message || 'Search error');
          }
        }
      } catch (e) {
        console.error('Error parsing SSE data:', e);
        if (onError) {
          onError('Error parsing search results');
        }
      }
    };
    
    eventSource.onerror = (error) => {
      console.error('SSE error:', error);
      eventSource.close();
      if (onError) {
        onError('Connection error. Please try again.');
      }
    };
    
    // Return eventSource so it can be closed manually
    return eventSource;
  },

  // New async search (returns job ID immediately - NO TIMEOUT)
  searchAsync: async (query, searchType = 'all', dateRange = 'all') => {
    return await apiRequest('/search/async', {
      method: 'POST',
      body: { query, search_type: searchType, date_range: dateRange }
    });
  },

  // Get job status (for polling)
  getJobStatus: async (jobId) => {
    return await apiRequest(`/search/job-status?jobId=${jobId}`, {
      method: 'GET'
    });
  },

  getHistory: async (limit = 50, offset = 0) => {
    return await apiRequest(`/search/history?limit=${limit}&offset=${offset}`, {
      method: 'GET'
    });
  },

  getResults: async (searchId) => {
    return await apiRequest(`/search/results?searchId=${searchId}`, {
      method: 'GET'
    });
  }
};

// Subscription API
const subscriptionAPI = {
  getPlans: async () => {
    return await apiRequest('/subscriptions/plans', {
      method: 'GET'
    });
  },

  subscribe: async (planType, duration) => {
    return await apiRequest('/subscriptions/subscribe', {
      method: 'POST',
      body: { plan_type: planType, duration }
    });
  },

  getCurrent: async () => {
    return await apiRequest('/subscriptions/current', {
      method: 'GET'
    });
  }
};

// API Keys API (User-facing)
const apiKeysAPI = {
  generate: async () => {
    return await apiRequest('/keys/generate', {
      method: 'POST'
    });
  },

  getAll: async (queryString = '') => {
    // Use /keys/index instead of /keys to point to keys/index.php
    const url = queryString.startsWith('?')
      ? `/keys/index${queryString}`
      : queryString
        ? `/keys/index?${queryString}`
        : '/keys/index';
    return await apiRequest(url, {
      method: 'GET'
    });
  },

  delete: async (keyId) => {
    // Send keyId in body for DELETE request
    return await apiRequest('/keys/index', {
      method: 'DELETE',
      body: { keyId: keyId }
    });
  }
};

// Payment API
const paymentAPI = {
  create: async (planType, duration, cryptocurrency) => {
    return await apiRequest('/payments/create', {
      method: 'POST',
      body: { plan_type: planType, duration, cryptocurrency }
    });
  },

  submit: async (paymentId, transactionHash) => {
    return await apiRequest('/payments/submit', {
      method: 'POST',
      body: { paymentId, transactionHash }
    });
  },

  getWallets: async () => {
    return await apiRequest('/payments/wallets', {
      method: 'GET'
    });
  }
};

// Admin API
const adminAPI = {
  getUsers: async (queryString = '') => {
    // queryString can be full query string like "?search=test&page=1&limit=20"
    // or just the query part "search=test&page=1&limit=20"
    const url = queryString.startsWith('?') 
      ? `/admin/users${queryString}`
      : queryString 
        ? `/admin/users?${queryString}`
        : '/admin/users';
    return await apiRequest(url, {
      method: 'GET'
    });
  },

  getPayments: async (queryString = '') => {
    // queryString can be full query string like "?status=pending&page=1&limit=20"
    // or just the query part "status=pending&page=1&limit=20"
    const url = queryString.startsWith('?') 
      ? `/admin/payments${queryString}`
      : queryString 
        ? `/admin/payments?${queryString}`
        : '/admin/payments';
    return await apiRequest(url, {
      method: 'GET'
    });
  },

  approvePayment: async (requestId, adminNotes = null) => {
    return await apiRequest('/admin/payments', {
      method: 'PUT',
      body: { requestId, action: 'approve', adminNotes }
    });
  },

  rejectPayment: async (requestId, adminNotes = null) => {
    return await apiRequest('/admin/payments', {
      method: 'PUT',
      body: { requestId, action: 'reject', adminNotes }
    });
  },

  getWallets: async () => {
    return await apiRequest('/admin/wallets', {
      method: 'GET'
    });
  },

  addWallet: async (cryptocurrency, symbol, walletAddress, network = null, logoUrl = null, qrCodeUrl = null, exchangeRate = null) => {
    return await apiRequest('/admin/wallets', {
      method: 'POST',
      body: { cryptocurrency, symbol, wallet_address: walletAddress, network, logo_url: logoUrl, qr_code_url: qrCodeUrl, exchange_rate: exchangeRate }
    });
  },

  updateWallet: async (id, cryptocurrency, symbol, walletAddress, network = null, isActive = true, logoUrl = null, qrCodeUrl = null, exchangeRate = null) => {
    return await apiRequest('/admin/wallets', {
      method: 'PUT',
      body: { id, cryptocurrency, symbol, wallet_address: walletAddress, network, is_active: isActive, logo_url: logoUrl, qr_code_url: qrCodeUrl, exchange_rate: exchangeRate }
    });
  },

  deleteWallet: async (id) => {
    return await apiRequest(`/admin/wallets?id=${id}`, {
      method: 'DELETE'
    });
  },

  updateUser: async (userId, email = null, username = null) => {
    return await apiRequest('/admin/users', {
      method: 'PUT',
      body: { userId, action: 'update', email, username }
    });
  },

  updateUserPassword: async (userId, password) => {
    return await apiRequest('/admin/users', {
      method: 'PUT',
      body: { userId, action: 'password', password }
    });
  },

  updateUserSubscription: async (userId, subscriptionType, subscriptionStatus, expiresAt = null) => {
    return await apiRequest('/admin/users', {
      method: 'PUT',
      body: { 
        userId, 
        action: 'subscription', 
        subscription_type: subscriptionType,
        subscription_status: subscriptionStatus,
        subscription_expires_at: expiresAt
      }
    });
  },

  login: async (username, password, recaptchaToken) => {
    return await apiRequest('/admin/login', {
      method: 'POST',
      body: { username, password, recaptcha_token: recaptchaToken }
    });
  },

  getStats: async () => {
    return await apiRequest('/admin/stats', {
      method: 'GET'
    });
  },

  getRecentActivity: async (limit = 10) => {
    return await apiRequest(`/admin/activity/recent?limit=${limit}`, {
      method: 'GET'
    });
  },

  getSearchHistory: async (queryString = '') => {
    const url = queryString.startsWith('?')
      ? `/admin/search-history${queryString}`
      : queryString
        ? `/admin/search-history?${queryString}`
        : '/admin/search-history';
    return await apiRequest(url, {
      method: 'GET'
    });
  },

  deleteSearchHistory: async (id) => {
    return await apiRequest(`/admin/search-history?id=${id}`, {
      method: 'DELETE'
    });
  },

  getActivityLogs: async (queryString = '') => {
    const url = queryString.startsWith('?')
      ? `/admin/activity-logs${queryString}`
      : queryString
        ? `/admin/activity-logs?${queryString}`
        : '/admin/activity-logs';
    return await apiRequest(url, {
      method: 'GET'
    });
  },

  getSettings: async () => {
    return await apiRequest('/admin/settings', {
      method: 'GET'
    });
  },

  updateSettings: async (settings) => {
    return await apiRequest('/admin/settings', {
      method: 'PUT',
      body: settings
    });
  },

  getNotifications: async () => {
    return await apiRequest('/admin/notifications', {
      method: 'GET'
    });
  },

  bulkUpdateUsers: async (userIds, updates) => {
    return await apiRequest('/admin/users/bulk-update', {
      method: 'POST',
      body: { user_ids: userIds, updates }
    });
  },

  bulkDeleteUsers: async (userIds) => {
    return await apiRequest('/admin/users/bulk-delete', {
      method: 'POST',
      body: { user_ids: userIds }
    });
  },

  createApiKey: async (userId) => {
    return await apiRequest('/admin/api-keys', {
      method: 'POST',
      body: { user_id: userId }
    });
  }
};

// Support API
const supportAPI = {
  getContactInfo: async () => {
    return await apiRequest('/support/contact-info', {
      method: 'GET'
    });
  },

  updateContactInfo: async (email, telegram) => {
    return await apiRequest('/support/contact-info', {
      method: 'PUT',
      body: { email, telegram }
    });
  },

  createTicket: async (subject, message, priority = 'medium') => {
    return await apiRequest('/support/tickets', {
      method: 'POST',
      body: { subject, message, priority }
    });
  },

  getMyTickets: async () => {
    return await apiRequest('/support/tickets', {
      method: 'GET'
    });
  },

  getTicket: async (ticketId) => {
    return await apiRequest(`/support/ticket-detail?id=${ticketId}`, {
      method: 'GET'
    });
  },

  replyToTicket: async (ticketId, message) => {
    return await apiRequest(`/support/ticket-detail?id=${ticketId}`, {
      method: 'POST',
      body: { message }
    });
  },

  updateTicketStatus: async (ticketId, status, priority = null) => {
    return await apiRequest(`/support/ticket-detail?id=${ticketId}`, {
      method: 'PUT',
      body: { status, priority }
    });
  },

  getAllTickets: async (filters = {}) => {
    const queryString = new URLSearchParams(filters).toString();
    const url = queryString ? `/support/tickets?${queryString}` : '/support/tickets';
    return await apiRequest(url, {
      method: 'GET'
    });
  }
};

// Public Settings API (no auth required)
const publicSettingsAPI = {
  get: async () => {
    return await apiRequest('/settings/public', {
      method: 'GET'
    });
  }
};

// Export API functions
window.API = {
  auth: authAPI,
  user: userAPI,
  search: searchAPI,
  subscription: subscriptionAPI,
  apiKeys: apiKeysAPI,
  payment: paymentAPI,
  admin: adminAPI,
  support: supportAPI,
  publicSettings: publicSettingsAPI,
  getAuthToken,
  setAuthToken,
  removeAuthToken
};

// Verify API support is loaded
if (typeof window.API !== 'undefined' && window.API.support) {
  console.log('✓ API.support loaded successfully');
} else {
  console.error('✗ API.support failed to load');
}

