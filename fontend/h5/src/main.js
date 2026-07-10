import { createApp } from 'vue';
import Vant from 'vant';
import 'vant/lib/index.css';
import App from './App.vue';
import './styles.css';
import './styles/tokens.css';
import './styles/base.css';
import './styles/motion.css';
import './styles/forms.css';
import './styles/utilities.css';

createApp(App).use(Vant).mount('#app');
