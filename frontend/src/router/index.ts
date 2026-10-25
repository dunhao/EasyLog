import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';

const Layout = () => import('@/layouts/DefaultLayout.vue');

// 路由与原型页面一一对应
export const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: Layout,
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/views/DashboardView.vue'),
        meta: { title: '仪表盘', icon: 'Odometer', nav: 'dashboard' },
      },
      {
        path: 'search',
        name: 'search',
        component: () => import('@/views/SearchView.vue'),
        meta: { title: '日志检索', icon: 'Search', nav: 'search' },
      },
      {
        path: 'topics',
        name: 'topics',
        component: () => import('@/views/TopicsView.vue'),
        meta: { title: '日志主题', icon: 'Files', nav: 'topics' },
      },
      {
        path: 'endpoints',
        name: 'endpoints',
        component: () => import('@/views/EndpointsView.vue'),
        meta: { title: '接入点', icon: 'Connection', nav: 'endpoints' },
      },
      {
        path: 'alerts',
        name: 'alerts',
        component: () => import('@/views/AlertsView.vue'),
        meta: { title: '告警', icon: 'Bell', nav: 'alerts' },
      },
      {
        path: 'dashboards',
        name: 'dashboards',
        component: () => import('@/views/DashboardsView.vue'),
        meta: { title: '自定义仪表盘', icon: 'DataLine', nav: 'dashboards' },
      },
      {
        path: 'workspaces',
        name: 'workspaces',
        component: () => import('@/views/WorkspacesView.vue'),
        meta: { title: '工作空间', icon: 'Folder', nav: 'workspaces' },
      },
      {
        path: 'users',
        name: 'users',
        component: () => import('@/views/UsersView.vue'),
        meta: { title: '用户与权限', icon: 'User', nav: 'users' },
      },
      {
        path: 'usage',
        name: 'usage',
        component: () => import('@/views/UsageView.vue'),
        meta: { title: '用量统计', icon: 'TrendCharts', nav: 'usage' },
      },
      {
        path: 'consumption',
        name: 'consumption',
        component: () => import('@/views/ConsumptionView.vue'),
        meta: { title: '消费明细', icon: 'Wallet', nav: 'consumption' },
      },
      {
        path: 'shipping',
        name: 'shipping',
        component: () => import('@/views/ShippingView.vue'),
        meta: { title: '投递与导出', icon: 'Promotion', nav: 'shipping' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('@/views/SettingsView.vue'),
        meta: { title: '平台设置', icon: 'Setting', nav: 'settings' },
      },
    ],
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { title: '登录', public: true },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { title: '404', public: true },
  },
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

router.beforeEach((to, _from, next) => {
  const title = (to.meta.title as string) || 'EasyLog';
  document.title = `${title} · EasyLog`;

  // 登录守卫：非 public 路由要求已有 token
  const token = localStorage.getItem('easylog_token');
  if (!to.meta.public && !token) {
    next({ path: '/login', query: { redirect: to.fullPath } });
    return;
  }
  if (to.path === '/login' && token) {
    next('/');
    return;
  }
  next();
});

export default router;
