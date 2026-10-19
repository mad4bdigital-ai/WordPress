'use strict';

if ( window.Vue && Vue.config ) {
	Vue.config.devtools = true;
}

window.JetDashboardEventBus = new Vue();

window.JetDasboard = new JetDasboardClass();

//window.JetDasboard.initVueComponents();

window.JetDasboardPageInstance = JetDasboard.initDashboardPageInstance();
