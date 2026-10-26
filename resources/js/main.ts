// import './bootstrap';

// 子路径部署前缀拦截必须最先安装（早于一切 API 调用与组件挂载）：
// 根部署（BASE 为空）时不打任何补丁，行为与旧版完全一致
import { installBasePath } from './lib/url';
installBasePath();

import { createApp } from "vue";
import { createI18n } from 'vue-i18n'
import language from './language'
const i18n = createI18n(language)
import router from './router';

import App from "./App.vue";

import '../css/button.css';

const app = createApp(App);
app.use(router);
app.use(i18n)
app.mount("#app");
