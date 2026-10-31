import "./bootstrap";
import Alpine from "alpinejs";
import { registerMaps } from "./maps";
import { paymentStatus } from "./payment-status";
import { registerPhoneInput } from "./phone-input";
import { registerTour } from "./tour";

window.Alpine = Alpine;
window.paymentStatus = paymentStatus;
registerMaps(Alpine);
registerPhoneInput(Alpine);
registerTour(Alpine);
Alpine.start();
