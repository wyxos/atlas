import Pusher from 'pusher-js';
import { connectReverbWithCtor, type PusherCtor } from './reverb-client-base';
import type {
    ReverbClient,
    ReverbConfig,
    ReverbConnectionState,
    ReverbEventName,
    ReverbEventPayload,
    ReverbSubscription,
} from './reverb-types';

async function connectReverb(config: ReverbConfig): Promise<ReverbClient | null> {
    return connectReverbWithCtor(config, Pusher as unknown as PusherCtor);
}

export {
    connectReverb,
};

export type {
    ReverbClient,
    ReverbConfig,
    ReverbConnectionState,
    ReverbEventName,
    ReverbEventPayload,
    ReverbSubscription,
};
