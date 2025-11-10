<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarGroup, SidebarGroupLabel, SidebarMenuSub, SidebarMenuSubItem } from '@/components/ui/sidebar';
import { dashboard, users as usersRoute, files as filesRoute, photos as photosRoute, reels as reelsRoute } from '@/routes';
import { type NavItem } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { LayoutGrid, Users, Music, Image, Video, Heart, ThumbsUp, Laugh, ThumbsDown, HelpCircle, ChevronDown, ChevronUp, Shuffle, Play, Search, FileText, AlertTriangle, Eye, EyeOff, Cog, MinusCircle, Music2 } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';
import { useAudioPlayer, type AudioTrack } from '@/stores/audio';
import * as AudioController from '@/actions/App/Http/Controllers/AudioController';
import * as AudioReactionsController from '@/actions/App/Http/Controllers/AudioReactionsController';
import axios from 'axios';

function isAudioReactionActive(type: string): boolean {
  const url = page.url as string;
  return url.startsWith(`/audio/${type}`);
}

const page = usePage();

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Browse',
        href: '/browse',
        icon: Search,
    },
    // Users (admin only)
    ...(page.props.auth?.user?.is_admin ? [
        {
            title: 'Users',
            href: usersRoute(),
            icon: Users,
        } as NavItem,
    ] : []),
];

function initialOpen(section: 'audio' | 'images' | 'videos' | 'files' | 'disliked' | 'videosDisliked'): boolean {
  try {
    const raw = localStorage.getItem(openStateKey);
    const st = raw ? JSON.parse(raw) : {};
    if (typeof st[section] === 'boolean') return !!st[section];
  } catch {}
  const url = page.url as string;
  if (section === 'audio') return url.startsWith('/audio/');
  if (section === 'images') return url.startsWith('/photos');
  if (section === 'videos') return url.startsWith('/reels');
  if (section === 'files') return url.startsWith('/files');
  if (section === 'disliked') return url.startsWith('/photos/disliked');
  if (section === 'videosDisliked') return url.startsWith('/reels/disliked');
  return false;
}

const isFilesOpen = ref(initialOpen('files'));
const isImagesOpen = ref(initialOpen('images'));
const isVideosOpen = ref(initialOpen('videos'));
const isAudioOpen = ref(initialOpen('audio'));
const isDislikedOpen = ref(initialOpen('disliked'));
const isVideosDislikedOpen = ref(initialOpen('videosDisliked'));

// Persist open/closed state per section
const openStateKey = 'atlas:sidebar-open';

function saveOpenState() {
  try {
    const st = {
      audio: isAudioOpen.value,
      images: isImagesOpen.value,
      videos: isVideosOpen.value,
      files: isFilesOpen.value,
      disliked: isDislikedOpen.value,
      videosDisliked: isVideosDislikedOpen.value,
    };
    localStorage.setItem(openStateKey, JSON.stringify(st));
  } catch {}
}
watch([isAudioOpen, isImagesOpen, isVideosOpen, isFilesOpen, isDislikedOpen, isVideosDislikedOpen], saveOpenState);

function visit(url: string) {
  if (!url || url === '#') return;
  router.visit(url);
}

const { setQueueAndPlay, setQueueAndShuffle, play } = useAudioPlayer();

async function queueAudioReaction(type: string, opts: { shuffle?: boolean } = {}) {
  try {
    // Fetch audio reaction files using the data endpoint (returns JSON)
    const action = AudioReactionsController.data(type);
    const response = await axios.get(action.url);
    const playlistFileIds = response.data.playlistFileIds || [];

    if (playlistFileIds.length === 0) {
      console.warn(`No files found for audio reaction type: ${type}`);
      return;
    }

    // Build queue items - we only need id and url, metadata will be loaded lazily
    const queueItems: AudioTrack[] = playlistFileIds.map((fileId: number) => {
      const streamUrl = AudioController.stream({ file: fileId }).url;
      return {
        id: fileId,
        url: streamUrl,
      };
    });

    if (queueItems.length === 0) return;

    if (opts.shuffle) {
      // Shuffle the queue before setting it
      const shuffled = [...queueItems];
      for (let i = shuffled.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
      }
      await setQueueAndShuffle(shuffled, queueItems, { autoPlay: false });
      await play();
    } else {
      await setQueueAndPlay(queueItems, 0, { autoPlay: true });
    }
  } catch (e) {
    console.error(`Failed to queue audio ${type}`, e);
  }
}


function openAndVisit(section: 'audio' | 'images' | 'videos' | 'files') {
  if (section === 'audio') {
    if (isAudioOpen.value) { isAudioOpen.value = false; return; }
    isAudioOpen.value = true;
    visit('/audio/all');
  } else if (section === 'files') {
    if (isFilesOpen.value) { isFilesOpen.value = false; return; }
    isFilesOpen.value = true;
    visit(filesRoute());
  } else if (section === 'images') {
    if (isImagesOpen.value) { isImagesOpen.value = false; return; }
    isImagesOpen.value = true;
    visit(photosRoute());
  } else if (section === 'videos') {
    if (isVideosOpen.value) { isVideosOpen.value = false; return; }
    isVideosOpen.value = true;
    visit(reelsRoute());
  }
}

const footerNavItems: NavItem[] = [];

function onEnter(el: HTMLElement) {
  el.style.maxHeight = '0px';
  el.style.overflow = 'hidden';
  // Force reflow
  void el.offsetHeight;
  el.style.transition = 'max-height 200ms ease, opacity 150ms ease';
  el.style.maxHeight = el.scrollHeight + 'px';
  el.style.opacity = '1';
}
function onAfterEnter(el: HTMLElement) {
  el.style.maxHeight = 'none';
  el.style.overflow = '';
  el.style.transition = '';
}
function onLeave(el: HTMLElement) {
  el.style.maxHeight = el.scrollHeight + 'px';
  el.style.overflow = 'hidden';
  // Force reflow
  void el.offsetHeight;
  el.style.transition = 'max-height 200ms ease, opacity 150ms ease';
  el.style.maxHeight = '0px';
  el.style.opacity = '0';
}
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <!-- Platform group at top -->
            <NavMain :items="mainNavItems" />

            <!-- Library groups -->
            <SidebarGroup class="px-2 py-0">
                <SidebarGroupLabel>Library</SidebarGroupLabel>

                <!-- Audio (on-the-fly by reactions) -->
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton class="flex items-center justify-between" :tooltip="'Audio'" @click="openAndVisit('audio')">
                            <div class="flex items-center gap-2">
                                <component :is="Music" :size="18" />
                                <span>Audio</span>
                            </div>
                            <component :is="isAudioOpen ? ChevronUp : ChevronDown" :size="16" />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <Transition @enter="onEnter" @after-enter="onAfterEnter" @leave="onLeave">
                      <div v-show="isAudioOpen">
                        <SidebarMenuSub>
                          <!-- Static audio reaction filters -->
                          <SidebarMenuSubItem>
                              <div class="group/row flex items-center justify-between">
                                <SidebarMenuButton as-child :tooltip="'All songs'" class="flex-1">
                                  <Link href="/audio/all" :class="isAudioReactionActive('all') ? 'bg-accent/40 rounded-md' : ''">
                                      <component :is="Music" :size="18" />
                                      <span>All songs</span>
                                  </Link>
                                </SidebarMenuButton>
                                <div class="ml-2 hidden items-center gap-1 pr-2 group-hover/row:flex">
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Play" data-test="sidebar-all-play" @click.stop="queueAudioReaction('all')">
                                    <Play :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Shuffle" data-test="sidebar-all-shuffle" @click.stop="queueAudioReaction('all', { shuffle: true })">
                                    <Shuffle :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                </div>
                              </div>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                              <div class="group/row flex items-center justify-between">
                                <SidebarMenuButton as-child :tooltip="'Favorites'" class="flex-1">
                                  <Link href="/audio/favorites" :class="isAudioReactionActive('favorites') ? 'bg-accent/40 rounded-md' : ''">
                                      <component :is="Heart" :size="18" />
                                      <span>Favorites</span>
                                  </Link>
                                </SidebarMenuButton>
                                <div class="ml-2 hidden items-center gap-1 pr-2 group-hover/row:flex">
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Play" data-test="sidebar-favorites-play" @click.stop="queueAudioReaction('favorites')">
                                    <Play :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Shuffle" data-test="sidebar-favorites-shuffle" @click.stop="queueAudioReaction('favorites', { shuffle: true })">
                                    <Shuffle :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                </div>
                              </div>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                              <div class="group/row flex items-center justify-between">
                                <SidebarMenuButton as-child :tooltip="'Liked'" class="flex-1">
                                  <Link href="/audio/liked" :class="isAudioReactionActive('liked') ? 'bg-accent/40 rounded-md' : ''">
                                      <component :is="ThumbsUp" :size="18" />
                                      <span>Liked</span>
                                  </Link>
                                </SidebarMenuButton>
                                <div class="ml-2 hidden items-center gap-1 pr-2 group-hover/row:flex">
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Play" data-test="sidebar-liked-play" @click.stop="queueAudioReaction('liked')">
                                    <Play :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Shuffle" data-test="sidebar-liked-shuffle" @click.stop="queueAudioReaction('liked', { shuffle: true })">
                                    <Shuffle :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                </div>
                              </div>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                              <div class="group/row flex items-center justify-between">
                                <SidebarMenuButton as-child :tooltip="'Funny'" class="flex-1">
                                  <Link href="/audio/funny" :class="isAudioReactionActive('funny') ? 'bg-accent/40 rounded-md' : ''">
                                      <component :is="Laugh" :size="18" />
                                      <span>Funny</span>
                                  </Link>
                                </SidebarMenuButton>
                                <div class="ml-2 hidden items-center gap-1 pr-2 group-hover/row:flex">
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Play" data-test="sidebar-funny-play" @click.stop="queueAudioReaction('funny')">
                                    <Play :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Shuffle" data-test="sidebar-funny-shuffle" @click.stop="queueAudioReaction('funny', { shuffle: true })">
                                    <Shuffle :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                </div>
                              </div>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                              <div class="group/row flex items-center justify-between">
                                <SidebarMenuButton as-child :tooltip="'Disliked'" class="flex-1">
                                  <Link href="/audio/disliked" :class="isAudioReactionActive('disliked') ? 'bg-accent/40 rounded-md' : ''">
                                      <component :is="ThumbsDown" :size="18" />
                                      <span>Disliked</span>
                                  </Link>
                                </SidebarMenuButton>
                                <div class="ml-2 hidden items-center gap-1 pr-2 group-hover/row:flex">
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Play" data-test="sidebar-disliked-play" @click.stop="queueAudioReaction('disliked')">
                                    <Play :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Shuffle" data-test="sidebar-disliked-shuffle" @click.stop="queueAudioReaction('disliked', { shuffle: true })">
                                    <Shuffle :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                </div>
                              </div>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                              <div class="group/row flex items-center justify-between">
                                <SidebarMenuButton as-child :tooltip="'Missing'" class="flex-1">
                                  <Link href="/audio/missing" :class="isAudioReactionActive('missing') ? 'bg-accent/40 rounded-md' : ''">
                                      <component :is="AlertTriangle" :size="18" />
                                      <span>Missing</span>
                                  </Link>
                                </SidebarMenuButton>
                                <div class="ml-2 hidden items-center gap-1 pr-2 group-hover/row:flex">
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Play" data-test="sidebar-missing-play" @click.stop="queueAudioReaction('missing')">
                                    <Play :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Shuffle" data-test="sidebar-missing-shuffle" @click.stop="queueAudioReaction('missing', { shuffle: true })">
                                    <Shuffle :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                </div>
                              </div>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                              <div class="group/row flex items-center justify-between">
                                <SidebarMenuButton as-child :tooltip="'Unrated'" class="flex-1">
                                  <Link href="/audio/unrated" :class="isAudioReactionActive('unrated') ? 'bg-accent/40 rounded-md' : ''">
                                      <component :is="MinusCircle" :size="18" />
                                      <span>Unrated</span>
                                  </Link>
                                </SidebarMenuButton>
                                <div class="ml-2 hidden items-center gap-1 pr-2 group-hover/row:flex">
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Play" data-test="sidebar-unrated-play" @click.stop="queueAudioReaction('unrated')">
                                    <Play :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Shuffle" data-test="sidebar-unrated-shuffle" @click.stop="queueAudioReaction('unrated', { shuffle: true })">
                                    <Shuffle :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                </div>
                              </div>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem v-if="page.props.hasSpotifyFiles">
                              <div class="group/row flex items-center justify-between">
                                <SidebarMenuButton as-child :tooltip="'Spotify'" class="flex-1">
                                  <Link href="/audio/spotify" :class="isAudioReactionActive('spotify') ? 'bg-accent/40 rounded-md' : ''">
                                      <component :is="Music2" :size="18" />
                                      <span>Spotify</span>
                                  </Link>
                                </SidebarMenuButton>
                                <div class="ml-2 hidden items-center gap-1 pr-2 group-hover/row:flex">
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Play" data-test="sidebar-spotify-play" @click.stop="queueAudioReaction('spotify')">
                                    <Play :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                  <button class="group/button p-1 rounded-md hover:bg-primary" title="Shuffle" data-test="sidebar-spotify-shuffle" @click.stop="queueAudioReaction('spotify', { shuffle: true })">
                                    <Shuffle :size="14" class="text-muted-foreground group-hover/button:text-white" />
                                  </button>
                                </div>
                              </div>
                          </SidebarMenuSubItem>

                          <!-- (No user-created playlists surfaced) -->
                        </SidebarMenuSub>
                      </div>
                    </Transition>
                </SidebarMenu>

                <!-- Photos (Images) -->
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton class="flex items-center justify-between" :tooltip="'Photos'" @click="openAndVisit('images')">
                            <div class="flex items-center gap-2">
                                <component :is="Image" :size="18" />
                                <span>Photos</span>
                            </div>
                            <component :is="isImagesOpen ? ChevronUp : ChevronDown" :size="16" />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <Transition @enter="onEnter" @after-enter="onAfterEnter" @leave="onLeave">
                      <div v-show="isImagesOpen">
<SidebarMenuSub>
                          <!-- All photos -->
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'All photos'">
                              <Link :href="photosRoute()">
                                <component :is="Image" :size="18" />
                                <span>All photos</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>

                          <!-- Disliked group with sub-items -->
                          <SidebarMenuSubItem>
                            <SidebarMenuButton class="flex items-center justify-between" :tooltip="'Disliked'" @click="() => { if (isDislikedOpen) { isDislikedOpen = false; } else { isDislikedOpen = true; router.visit('/photos/disliked/all'); } }">
                              <div class="flex items-center gap-2">
                                <component :is="ThumbsDown" :size="18" />
                                <span>Disliked</span>
                              </div>
                              <component :is="isDislikedOpen ? ChevronUp : ChevronDown" :size="16" />
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <Transition @enter="onEnter" @after-enter="onAfterEnter" @leave="onLeave">
                            <div v-show="isDislikedOpen">
                              <SidebarMenuSub class="ml-6">
                                <SidebarMenuSubItem>
                                  <SidebarMenuButton as-child :tooltip="'All'">
                                    <Link href="/photos/disliked/all">
                                      <component :is="ThumbsDown" :size="18" />
                                      <span>all</span>
                                    </Link>
                                  </SidebarMenuButton>
                                </SidebarMenuSubItem>
                                <SidebarMenuSubItem>
                                  <SidebarMenuButton as-child :tooltip="'Manual'">
                                    <Link href="/photos/disliked/manual">
                                      <component :is="ThumbsDown" :size="18" />
                                      <span>manual</span>
                                    </Link>
                                  </SidebarMenuButton>
                                </SidebarMenuSubItem>
                                <SidebarMenuSubItem>
                                  <SidebarMenuButton as-child :tooltip="'Ignored'">
                                    <Link href="/photos/disliked/ignored">
                                      <component :is="EyeOff" :size="18" />
                                      <span>ignored</span>
                                    </Link>
                                  </SidebarMenuButton>
                                </SidebarMenuSubItem>
                                <SidebarMenuSubItem>
                                  <SidebarMenuButton as-child :tooltip="'Auto'">
                                    <Link href="/photos/disliked/auto">
                                      <component :is="Cog" :size="18" />
                                      <span>auto</span>
                                    </Link>
                                  </SidebarMenuButton>
                                </SidebarMenuSubItem>
                                <SidebarMenuSubItem>
                                  <SidebarMenuButton as-child :tooltip="'Not disliked'">
                                    <Link href="/photos/disliked/not-disliked">
                                      <component :is="AlertTriangle" :size="18" />
                                      <span>not disliked</span>
                                    </Link>
                                  </SidebarMenuButton>
                                </SidebarMenuSubItem>
                              </SidebarMenuSub>
                            </div>
                          </Transition>

                          <!-- Reaction feeds -->
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Favorites'">
                              <Link href="/photos/reactions/favorites">
                                <component :is="Heart" :size="18" />
                                <span>Favorites</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Liked'">
                              <Link href="/photos/reactions/liked">
                                <component :is="ThumbsUp" :size="18" />
                                <span>Liked</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Funny'">
                              <Link href="/photos/reactions/funny">
                                <component :is="Laugh" :size="18" />
                                <span>Funny</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Unrated'">
                              <Link href="/photos/unrated">
                                <component :is="HelpCircle" :size="18" />
                                <span>Unrated</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Unpreviewed'">
                              <Link href="/photos/unpreviewed">
                                <component :is="Eye" :size="18" />
                                <span>Unpreviewed</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Unseen'">
                              <Link href="/photos/unseen">
                                <component :is="EyeOff" :size="18" />
                                <span>Unseen</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Not found'">
                              <Link href="/photos/not-found">
                                <component :is="AlertTriangle" :size="18" />
                                <span>Not found</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Manga'">
                              <Link href="#">
                                <component :is="HelpCircle" :size="18" />
                                <span>Manga</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Collection'">
                              <Link href="#">
                                <component :is="HelpCircle" :size="18" />
                                <span>Collection</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                        </SidebarMenuSub>
                      </div>
                    </Transition>
                </SidebarMenu>

                <!-- Reels (Videos) -->
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton class="flex items-center justify-between" :tooltip="'Reels'" @click="openAndVisit('videos')">
                            <div class="flex items-center gap-2">
                                <component :is="Video" :size="18" />
                                <span>Reels</span>
                            </div>
                            <component :is="isVideosOpen ? ChevronUp : ChevronDown" :size="16" />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <Transition @enter="onEnter" @after-enter="onAfterEnter" @leave="onLeave">
                      <div v-show="isVideosOpen">
                        <SidebarMenuSub>
                          <!-- All videos -->
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'All videos'">
                              <Link :href="reelsRoute()">
                                <component :is="Video" :size="18" />
                                <span>All videos</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>

                          <!-- Disliked group with sub-items (blacklisted) -->
                          <SidebarMenuSubItem>
                            <SidebarMenuButton class="flex items-center justify-between" :tooltip="'Disliked'" @click="() => { if (isVideosDislikedOpen) { isVideosDislikedOpen = false; } else { isVideosDislikedOpen = true; router.visit('/reels/disliked/all'); } }">
                              <div class="flex items-center gap-2">
                                <component :is="ThumbsDown" :size="18" />
                                <span>Disliked</span>
                              </div>
                              <component :is="isVideosDislikedOpen ? ChevronUp : ChevronDown" :size="16" />
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <Transition @enter="onEnter" @after-enter="onAfterEnter" @leave="onLeave">
                            <div v-show="isVideosDislikedOpen">
                              <SidebarMenuSub class="ml-6">
                                <SidebarMenuSubItem>
                                  <SidebarMenuButton as-child :tooltip="'All'">
                                    <Link href="/reels/disliked/all">
                                      <component :is="ThumbsDown" :size="18" />
                                      <span>all</span>
                                    </Link>
                                  </SidebarMenuButton>
                                </SidebarMenuSubItem>
                                <SidebarMenuSubItem>
                                  <SidebarMenuButton as-child :tooltip="'Manual'">
                                    <Link href="/reels/disliked/manual">
                                      <component :is="ThumbsDown" :size="18" />
                                      <span>manual</span>
                                    </Link>
                                  </SidebarMenuButton>
                                </SidebarMenuSubItem>
                                <SidebarMenuSubItem>
                                  <SidebarMenuButton as-child :tooltip="'Ignored'">
                                    <Link href="/reels/disliked/ignored">
                                      <component :is="EyeOff" :size="18" />
                                      <span>ignored</span>
                                    </Link>
                                  </SidebarMenuButton>
                                </SidebarMenuSubItem>
                                <SidebarMenuSubItem>
                                  <SidebarMenuButton as-child :tooltip="'Auto'">
                                    <Link href="/reels/disliked/auto">
                                      <component :is="Cog" :size="18" />
                                      <span>auto</span>
                                    </Link>
                                  </SidebarMenuButton>
                                </SidebarMenuSubItem>
                                <SidebarMenuSubItem>
                                  <SidebarMenuButton as-child :tooltip="'Not disliked'">
                                    <Link href="/reels/disliked/not-disliked">
                                      <component :is="AlertTriangle" :size="18" />
                                      <span>not disliked</span>
                                    </Link>
                                  </SidebarMenuButton>
                                </SidebarMenuSubItem>
                              </SidebarMenuSub>
                            </div>
                          </Transition>

                          <!-- Reaction feeds -->
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Favorites'">
                              <Link href="/reels/reactions/favorites">
                                <component :is="Heart" :size="18" />
                                <span>Favorites</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Liked'">
                              <Link href="/reels/reactions/liked">
                                <component :is="ThumbsUp" :size="18" />
                                <span>Liked</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Funny'">
                              <Link href="/reels/reactions/funny">
                                <component :is="Laugh" :size="18" />
                                <span>Funny</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Disliked (mine)'">
                              <Link href="/reels/reactions/disliked">
                                <component :is="ThumbsDown" :size="18" />
                                <span>Disliked</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Unrated'">
                              <Link href="/reels/unrated">
                                <component :is="HelpCircle" :size="18" />
                                <span>Unrated</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuButton as-child :tooltip="'Not found'">
                              <Link href="/reels/not-found">
                                <component :is="AlertTriangle" :size="18" />
                                <span>Not found</span>
                              </Link>
                            </SidebarMenuButton>
                          </SidebarMenuSubItem>
                        </SidebarMenuSub>
                      </div>
                    </Transition>
                </SidebarMenu>

                <!-- Vault (Files) -->
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton class="flex items-center justify-between" :tooltip="'Vault'" @click="openAndVisit('files')">
                            <div class="flex items-center gap-2">
                                <component :is="LayoutGrid" :size="18" />
                                <span>Vault</span>
                            </div>
                            <component :is="isFilesOpen ? ChevronUp : ChevronDown" :size="16" />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <Transition @enter="onEnter" @after-enter="onAfterEnter" @leave="onLeave">
                      <div v-show="isFilesOpen">
                        <SidebarMenuSub>
                          <SidebarMenuSubItem>
                              <SidebarMenuButton as-child :tooltip="'All files'">
                                  <Link :href="filesRoute()">
                                      <component :is="FileText" :size="18" />
                                      <span>All files</span>
                                  </Link>
                              </SidebarMenuButton>
                          </SidebarMenuSubItem>
                        </SidebarMenuSub>
                      </div>
                    </Transition>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
