import "./bootstrap";
import Alpine from "alpinejs";
import { registerMaps } from "./maps";

window.Alpine = Alpine;
registerMaps(Alpine);
Alpine.start();
