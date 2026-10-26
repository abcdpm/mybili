import { createRouter, createWebHistory } from 'vue-router';
import { BASE } from '@/lib/url';
import Home from '@/pages/Home.vue';
import About from '@/pages/About.vue';
import FavlistView from './pages/FavlistView.vue';
import VideoView from './pages/VideoView.vue';
import ProgressView from './pages/ProgressView.vue';
import CookieView from './pages/CookieView.vue';
import SettingsView from './pages/SettingsView.vue';
import SubscriptionView from './pages/Subscription.vue';
import SubscriptionVideoView from './pages/SubscriptionVideo.vue';
import VideoManagement from './pages/VideoManagement.vue';
import DownloadQueueView from './pages/DownloadQueueView.vue';
import LogView from './pages/LogView.vue'

const routes = [
  { path: '/', component: Home, meta: { keepAlive: true }  },
  { path: '/fav/:id', component: FavlistView, name:'favlist-id', meta: { keepAlive: true } },
  { path: '/fav/:id/video/:video_id', component: VideoView, name:'favlist-video-id' },
  { path: '/video/:id', component: VideoView, name:'video-id' },
  { path: '/about', component: About },
  { path: '/progress', component: ProgressView, name: 'progress', meta: { keepAlive: true } },
  { path: '/videos', component: VideoManagement, name: 'video-management' },
  { path: '/download-queue', component: DownloadQueueView, name: 'download-queue' },
  { path: '/cookie', component: CookieView },
  { path: '/settings', component: SettingsView },
  { path: '/subscription', component: SubscriptionView, name: 'subscription' },
  { path: '/subscription/:id', component: SubscriptionVideoView, name: 'subscription-id', meta: { keepAlive: true } },
  { path: '/subscription/:id/video/:video_id', component: VideoView, name: 'subscription-video-id' },
  { path: '/logs', component: LogView, name: 'LogView' },
];

const router = createRouter({
  // 子路径部署（/mybili）时 history base 必须带上前缀，否则刷新子路由 404；
  // 根部署时 BASE 为空 → undefined，行为与旧版完全一致
  history: createWebHistory(BASE || undefined),
  routes,
});

export default router;