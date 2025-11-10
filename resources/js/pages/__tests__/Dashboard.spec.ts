import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Dashboard from '@/pages/Dashboard.vue';

vi.mock('@inertiajs/vue3', () => ({
  Head: { name: 'Head', template: '<template><slot /></template>' },
  router: { visit: vi.fn() },
}));

describe('Dashboard.vue', () => {
  function mountDashboard(overrides: Partial<any> = {}) {
    const fileStats = overrides.fileStats ?? {
      audioFilesCount: 3,
      videoFilesCount: 2,
      imageFilesCount: 5,
      audioSpaceUsed: 10 * 1024 * 1024,
      videoSpaceUsed: 200 * 1024 * 1024,
      imageSpaceUsed: 30 * 1024 * 1024,
      diskSpaceUsedPercent: 42.5,
    };

    // Minimal stubs for layout and UI primitives
    const AppLayoutStub = { name: 'AppLayout', template: '<div data-test="layout"><slot /></div>' };
    const ButtonStub = { name: 'Button', template: '<button><slot /></button>' };
    const CardStub = { name: 'Card', template: '<div class="card"><slot /></div>' };
    const CardHeaderStub = { name: 'CardHeader', template: '<div class="card-header"><slot /></div>' };
    const CardContentStub = { name: 'CardContent', template: '<div class="card-content"><slot /></div>' };
    const CardTitleStub = { name: 'CardTitle', template: '<div class="card-title"><slot /></div>' };
    const CardDescriptionStub = { name: 'CardDescription', template: '<div class="card-description"><slot /></div>' };

    return mount(Dashboard, {
      props: { fileStats },
      global: {
        stubs: {
          AppLayout: AppLayoutStub,
          Button: ButtonStub,
          Card: CardStub,
          CardHeader: CardHeaderStub,
          CardContent: CardContentStub,
          CardTitle: CardTitleStub,
          CardDescription: CardDescriptionStub,
          PlaceholderPattern: true,
        },
      },
    });
  }

  it('renders KPI counts from props', () => {
    const wrapper = mountDashboard();

    expect(wrapper.get('[data-testid="stat-audio-count"]').text()).toBe('3');
    expect(wrapper.get('[data-testid="stat-video-count"]').text()).toBe('2');
    expect(wrapper.get('[data-testid="stat-image-count"]').text()).toBe('5');

    // disk percent rounded display
    expect(wrapper.get('[data-testid="stat-disk-percent"]').text()).toContain('42.5%');
  });
});

