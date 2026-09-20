import { createApp } from 'vue';
import Vant from 'vant';
import 'vant/lib/index.css';
import App from './App.vue';
import EnterpriseEvaluationPage from './features/internship/EnterpriseEvaluationPage.vue';
import './styles.css';
import './styles/tokens.css';
import './styles/base.css';
import './styles/motion.css';
import './styles/forms.css';
import './styles/utilities.css';
import { configureFilePreview } from '../../shared/filePreview';
import { request, backendUrl } from './api/client';
configureFilePreview({ request, backendUrl });

const enterpriseEvaluationToken = new URLSearchParams(window.location.search).get('enterprise_evaluation') || '';
const root = enterpriseEvaluationToken ? EnterpriseEvaluationPage : App;
const props = enterpriseEvaluationToken ? { token: enterpriseEvaluationToken } : {};

createApp(root, props).use(Vant).mount('#app');
