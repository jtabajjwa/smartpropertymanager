import "./bootstrap";
import Alpine from "alpinejs";
import { registerMaps } from "./maps";
import { paymentStatus } from "./payment-status";
import { registerPhoneInput } from "./phone-input";

window.Alpine = Alpine;
window.paymentStatus = paymentStatus;
registerMaps(Alpine);
registerPhoneInput(Alpine);
Alpine.start();
