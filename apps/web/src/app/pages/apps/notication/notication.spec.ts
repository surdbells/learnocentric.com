import { ComponentFixture, TestBed } from '@angular/core/testing';

import { Notication } from './notication';

describe('Notication', () => {
  let component: Notication;
  let fixture: ComponentFixture<Notication>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Notication]
    })
    .compileComponents();

    fixture = TestBed.createComponent(Notication);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
