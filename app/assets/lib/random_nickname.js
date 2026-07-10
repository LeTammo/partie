import { adjectives } from './adjectives.js';
import { animals } from './animals.js';

function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}

export function randomNickname() {
    const ad = pick(adjectives);
    const an = pick(animals);
    return `${ad.charAt(0).toUpperCase() + ad.slice(1)}${an.charAt(0).toUpperCase() + an.slice(1)}`;
}
